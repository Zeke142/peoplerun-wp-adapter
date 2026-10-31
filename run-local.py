import subprocess,pathlib,os,time,socket,sys,tarfile,hashlib,shutil
base=pathlib.Path(__file__).resolve().parent.parent
root=pathlib.Path(os.environ.get('O01_RUNTIME_ROOT',base/'runtime-local/usr')).resolve();data=base/'runtime-db-integrated'
env=os.environ.copy();env['LD_LIBRARY_PATH']=str(root/'lib/x86_64-linux-gnu');env['O01_DISPOSABLE_ACCEPTANCE']='YES';env['O01_EXTENSION_DIR']=str(root/'lib/php/20230831')
php=[str(root/'bin/php8.3'),'-n','-d','extension_dir='+env['O01_EXTENSION_DIR'],'-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=pdo','-d','extension=pdo_mysql']
package=pathlib.Path(__file__).resolve().parent
suite=sys.argv[1] if len(sys.argv)>1 else 'http'
if suite not in ['http','recovery','integrated']:raise RuntimeError('unknown suite')
archive=package/'vendor/wordpress-6.8.3.tar.gz'
if hashlib.sha256(archive.read_bytes()).hexdigest()!='92da34c9960e64d1258652c1ef73c517f7e46ac6dfd2dfc75436d3855af46b0c':raise RuntimeError('vendor archive changed')
if not (package/'wordpress/wp-settings.php').exists():
 with tarfile.open(archive) as tar:tar.extractall(package,filter='data')
shutil.copyfile(package/'wp-config.disposable.php',package/'wordpress/wp-config.php')
mu=package/'wordpress/wp-content/mu-plugins';mu.mkdir(parents=True,exist_ok=True)
for name in ['g1','g2']:shutil.copyfile(package/f'o01-wordpress-http/host-{name}-source.php',mu/f'{name}.php')
if not data.exists():
 subprocess.run([str(root/'bin/mariadb-install-db'),'--no-defaults','--basedir='+str(root),'--datadir='+str(data),'--auth-root-authentication-method=normal','--skip-test-db'],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=True)
p=subprocess.Popen([str(root/'sbin/mariadbd'),'--no-defaults','--user=root','--basedir='+str(root),'--datadir='+str(data),'--socket=','--bind-address=127.0.0.1','--port=33309','--pid-file='+str(data)+'.pid','--log-error='+str(data)+'.log'],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
try:
 for n in range(100):
  try:
   with socket.create_connection(('127.0.0.1',33309),timeout=.2):break
  except OSError:time.sleep(.1)
 else:raise RuntimeError('database readiness failed')
 if p.poll() is not None:raise RuntimeError('owned database process failed')
 env['O01_EXPECTED_DATADIR']=str(data.resolve())
 subprocess.run(php+['-r','$d=new PDO("mysql:host=127.0.0.1;port=33309","root","");if(realpath($d->query("SELECT @@datadir")->fetchColumn())!==getenv("O01_EXPECTED_DATADIR"))throw new RuntimeException("wrong_database_server");$d->exec("DROP DATABASE IF EXISTS o01_integrated_disposable; CREATE DATABASE o01_integrated_disposable");'],env=env,check=True)
 subprocess.run(php+[str(package/'setup-wordpress.php')],env=env,check=True)
 env['O01_RUNTIME_ROOT']=str(root)
 command=[sys.executable,str(package/'http-check.py')] if suite=='http' else php+[str(package/'o01-wordpress-http'/f'{suite}-check.php')]
 r=subprocess.run(command,env=env,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
 (package/f'{suite}-test-result.txt').write_text(r.stdout)
 print(r.stdout);print('EXIT',r.returncode)
 sys.exit(r.returncode)
finally:
 p.terminate();p.wait(timeout=20)
