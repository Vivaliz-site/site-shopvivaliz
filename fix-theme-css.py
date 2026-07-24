import re

path = 'css/shopvivaliz-unified-theme.css'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# Replace Vivaliz pseudo element
content = re.sub(r'\.product-image::after\s*\{\s*content:\s*"Vivaliz";', '.product-image::after { content: "";', content)
content = content.replace('content: "Vivaliz"', 'content: ""')
content = content.replace('content:"Vivaliz"', 'content:""')

# Replace opacity 0 on body
content = content.replace('body { opacity: 0;', 'body { opacity: 1;')
content = content.replace('body{opacity:0;', 'body{opacity:1;')

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)

print("✅ Cleaned shopvivaliz-unified-theme.css successfully!")
