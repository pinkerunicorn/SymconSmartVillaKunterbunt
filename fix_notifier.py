import json

file = 'SmartNotifier/module.json'
with open(file, 'r', encoding='utf-8') as f:
    data = json.load(f)

data['author'] = "Florian Graﬂinger"

# Ensure strict module json compliance: must have URL? Let's add it if missing, per critical rules
if 'url' not in data:
    data['url'] = "https://github.com/symcon"

with open(file, 'w', encoding='utf-8', newline='\n') as f:
    json.dump(data, f, indent=4, ensure_ascii=False)
