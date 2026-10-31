<?php
/**
 * Plugin Name: PeopleRun Instant Webpages — G1 Private Project Storage v3
 * Description: Private owner-scoped, idempotent project and immutable exact-byte revisions. Not a publishing service.
 * Version: 0.3.0
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

add_action('init', static function (): void {
    foreach (['pr_iw_project', 'pr_iw_revision'] as $type) {
        register_post_type($type, [
            'public' => false, 'publicly_queryable' => false,
            'exclude_from_search' => true, 'show_ui' => false,
            'show_in_rest' => false, 'rewrite' => false, 'query_var' => false,
            'supports' => ['title', 'author'],
        ]);
    }
});

function pr_iw_g1_v2_owner(int $project_id, int $user_id): bool {
    $p = get_post($project_id);
    return $user_id > 0 && get_current_user_id() === $user_id
        && $p instanceof WP_Post && $p->post_type === 'pr_iw_project'
        && $p->post_status === 'private' && (int)$p->post_author === $user_id;
}

/** Serialize competing operations across PHP workers; fail closed if MySQL locking is unavailable. */
function pr_iw_g1_v2_lock(string $scope): ?string {
    global $wpdb;
    $name = 'priw:' . substr(hash('sha256', $scope), 0, 48);
    $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name));
    return (string)$acquired === '1' ? $name : null;
}
function pr_iw_g1_v2_unlock(string $name): void {
    global $wpdb;
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
}
function pr_iw_g1_v2_find(string $type, int $user_id, string $key, int $parent = 0): int {
    $query = ['post_type'=>$type, 'post_status'=>'private', 'author'=>$user_id,
        'meta_key'=>'_pr_iw_request_key', 'meta_value'=>$key,
        'numberposts'=>1, 'fields'=>'ids', 'suppress_filters'=>true];
    if ($parent > 0) { $query['post_parent'] = $parent; }
    $found = get_posts($query);
    return $found ? (int)$found[0] : 0;
}
function pr_iw_g1_v2_key(int $owner, string $kind, string $key): string {
    return hash('sha256', $owner . ':' . $kind . ':' . $key);
}

function pr_iw_g1_v2_create_project(int $user_id, string $title, string $request_key) {
    if ($user_id < 1 || get_current_user_id() !== $user_id || trim($title) === '' || trim($request_key) === ''
        || strlen($request_key) > 120 || strlen($title) > 160) {
        return new WP_Error('invalid_or_unauthorized', 'Authenticated owner, short title and idempotency key required');
    }
    $key = pr_iw_g1_v2_key($user_id, 'create', $request_key);
    $lock = pr_iw_g1_v2_lock('project:' . $key);
    if ($lock === null) { return new WP_Error('lock_unavailable', 'Could not serialize project creation'); }
    try {
        $existing = pr_iw_g1_v2_find('pr_iw_project', $user_id, $key);
        if ($existing) { return ['project_id'=>$existing, 'replayed'=>true]; }
        $id = wp_insert_post(['post_type'=>'pr_iw_project', 'post_status'=>'private',
            'post_title'=>sanitize_text_field($title), 'post_author'=>$user_id], true);
        if (is_wp_error($id)) { return $id; }
        if (!add_post_meta((int)$id, '_pr_iw_request_key', $key, true)
            || !add_post_meta((int)$id, '_pr_iw_version', '0', true)) {
            wp_delete_post((int)$id, true);
            return new WP_Error('metadata_failure', 'Project metadata commit failed');
        }
        return ['project_id'=>(int)$id,'replayed'=>false];
    } finally { pr_iw_g1_v2_unlock($lock); }
}

/** At G1 the HTML is private source data, NEVER executable output. Publication needs a separate renderer/security gate. */
function pr_iw_g1_v2_safe_source(string $html): bool {
    // Stored as opaque private bytes, never rendered, exposed by REST, or served as executable content.
    // Source may contain RSVP forms, scripts, CSS, and other markup; the G3 renderer MUST sanitize.
    if ($html === '' || strlen($html) > 250000 || strpos($html, "\0") !== false) { return false; }
    return !preg_match('~-----BEGIN (?:RSA |OPENSSH |EC |DSA )?PRIVATE KEY-----~i', $html);
}

function pr_iw_g1_v2_add_revision(int $project_id, int $user_id, string $html, int $expected_version, string $request_key) {
    if (!pr_iw_g1_v2_owner($project_id, $user_id)) { return new WP_Error('forbidden', 'Owner required'); }
    if (trim($request_key) === '' || strlen($request_key) > 120 || $expected_version < 0) {
        return new WP_Error('invalid_input', 'Revision key or expected version invalid');
    }
    if (!pr_iw_g1_v2_safe_source($html)) { return new WP_Error('unsafe_source', 'Unsafe or oversized HTML source'); }
    $key = pr_iw_g1_v2_key($project_id, 'revision', $request_key);
    $lock = pr_iw_g1_v2_lock('revision:' . $project_id);
    if ($lock === null) { return new WP_Error('lock_unavailable', 'Could not serialize revisions'); }
    try {
        // Recheck owner after acquiring lock; do not trust an earlier snapshot.
        if (!pr_iw_g1_v2_owner($project_id, $user_id)) { return new WP_Error('forbidden', 'Owner changed'); }
        $existing = pr_iw_g1_v2_find('pr_iw_revision', $user_id, $key, $project_id);
        if ($existing) { return ['revision_id'=>$existing, 'replayed'=>true]; }
        $version = (int)get_post_meta($project_id, '_pr_iw_version', true);
        if ($version !== $expected_version) { return new WP_Error('version_conflict', 'Project revision has advanced'); }
        $hash = hash('sha256', $html);
        // Base64 in hidden postmeta retains full HTML/head/CSS bytes; post_content remains empty.
        $id = wp_insert_post(['post_type'=>'pr_iw_revision', 'post_status'=>'private',
            'post_author'=>$user_id, 'post_parent'=>$project_id,
            'post_title'=>'Revision '.($version + 1), 'post_content'=>''], true);
        if (is_wp_error($id)) { return $id; }
        $meta = [
            '_pr_iw_request_key'=>$key, '_pr_iw_html_b64'=>base64_encode($html),
            '_pr_iw_sha256'=>$hash, '_pr_iw_revision_number'=>(string)($version + 1),
        ];
        foreach ($meta as $k=>$v) {
            if (!add_post_meta((int)$id, $k, $v, true)) {
                wp_delete_post((int)$id, true);
                return new WP_Error('metadata_failure', 'Revision metadata commit failed');
            }
        }
        if (!update_post_meta($project_id, '_pr_iw_version', (string)($version + 1), (string)$version)) {
            wp_delete_post((int)$id, true);
            return new WP_Error('version_commit_failure', 'Project version commit failed');
        }
        return ['revision_id'=>(int)$id, 'project_id'=>$project_id,
            'content_sha256'=>$hash, 'version'=>$version + 1, 'replayed'=>false];
    } finally { pr_iw_g1_v2_unlock($lock); }
}

function pr_iw_g1_v2_read_revision(int $project_id, int $revision_id, int $user_id) {
    if (!pr_iw_g1_v2_owner($project_id, $user_id)) { return new WP_Error('forbidden', 'Owner required'); }
    $r = get_post($revision_id);
    if (!$r instanceof WP_Post || $r->post_type !== 'pr_iw_revision' || $r->post_status !== 'private'
        || (int)$r->post_parent !== $project_id || (int)$r->post_author !== $user_id) {
        return new WP_Error('not_found', 'Revision unavailable');
    }
    $encoded = (string)get_post_meta($revision_id, '_pr_iw_html_b64', true);
    $html = base64_decode($encoded, true);
    $expected = (string)get_post_meta($revision_id, '_pr_iw_sha256', true);
    if ($html === false || !hash_equals($expected, hash('sha256', $html))) {
        return new WP_Error('integrity_failure', 'Stored revision hash mismatch');
    }
    return ['revision_id'=>$revision_id, 'html'=>$html, 'content_sha256'=>$expected];
}
