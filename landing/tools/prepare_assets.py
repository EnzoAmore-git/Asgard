from pathlib import Path
from urllib.request import Request, urlopen
import hashlib
import re
from PIL import Image

root = Path(__file__).resolve().parents[1]
assets = root / 'dist' / 'assets'
assets.mkdir(exist_ok=True)
source = Path(r'C:\Users\Fabol\.codex\generated_images\01a16338-0505-7641-8996-c8603a720292\exec-fb76fe57-5eaf-4b20-bf5d-7bc5fc7672b0.png')
with Image.open(source) as im:
    im = im.convert('RGB')
    im.save(assets / 'nordic-voyage.webp', quality=88, method=6)
    im.resize((900, 600), Image.Resampling.LANCZOS).save(assets / 'nordic-voyage-small.webp', quality=85, method=6)

url = 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=Manrope:wght@400;500;600;700&display=swap'
request = Request(url, headers={'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'})
css = urlopen(request, timeout=30).read().decode()
font_urls = set(re.findall(r'url\((https://[^)]+)\)', css))
for remote in font_urls:
    ext = '.woff2' if '.woff2' in remote else '.ttf'
    filename = 'font-' + hashlib.sha256(remote.encode()).hexdigest()[:12] + ext
    (assets / filename).write_bytes(urlopen(remote, timeout=30).read())
    css = css.replace(remote, './' + filename)
(assets / 'fonts.css').write_text(css, encoding='utf-8')
for name in ('cormorant-garamond.ttf', 'manrope.ttf'):
    (assets / name).unlink(missing_ok=True)
print('Hero images and self-hosted fonts prepared:', len(font_urls), 'font files')
