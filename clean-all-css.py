import glob

for path in glob.glob('css/*.css'):
    with open(path, 'r', encoding='utf-8') as f:
        c = f.read()
    c2 = c.replace('content: "Vivaliz"', 'content: ""').replace('content:"Vivaliz"', 'content:""')
    c2 = c2.replace('body { opacity: 0;', 'body { opacity: 1;').replace('body{opacity:0;', 'body{opacity:1;')
    if c != c2:
        print(f"Updated {path}")
        with open(path, 'w', encoding='utf-8') as f:
            f.write(c2)
