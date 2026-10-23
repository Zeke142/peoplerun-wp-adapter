import io, pathlib, unittest, zipfile
from unittest.mock import patch
import generate as g
ARCHIVE=pathlib.Path(__file__).resolve().parents[2]/'dn29_source/PeopleRun/Bootstrap/O01_Checkpoints/PeopleRun_O01_Trust_Boundary_DN29_v1.zip'
class GeneratorChecks(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.raw=ARCHIVE.read_bytes()
    def test_deterministic_and_inverse(self):
        a,review=g.generate(self.raw);b,other=g.generate(self.raw)
        self.assertEqual(a,b);self.assertEqual(review,other)
        self.assertTrue(all(review['inverse_checks'].values()))
    def test_archive_drift(self):
        with self.assertRaises(ValueError):g.generate(self.raw+b'x')
    def test_ambiguous_transform(self):
        with self.assertRaises(ValueError):g.once('xx','x','y')
    def test_missing_anchor(self):
        with self.assertRaises(ValueError):g.segment('abc','start','end')
    def test_inner_source_drift(self):
        buf=io.BytesIO()
        with zipfile.ZipFile(io.BytesIO(self.raw)) as src,zipfile.ZipFile(buf,'w') as out:
            for p in src.namelist():out.writestr(p,src.read(p)+(b'\n' if p in g.PINS else b''))
        raw=buf.getvalue()
        with patch.object(g,'ARCHIVE',g.sha(raw)),self.assertRaisesRegex(ValueError,'runtime source pin'):g.generate(raw)
    def test_original_bytes_untouched(self):
        before=g.sha(ARCHIVE.read_bytes());g.generate(self.raw);self.assertEqual(before,g.sha(ARCHIVE.read_bytes()));self.assertEqual(before,g.ARCHIVE)
if __name__=='__main__':unittest.main()
