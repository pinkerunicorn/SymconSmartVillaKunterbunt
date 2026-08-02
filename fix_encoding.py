import os

file = 'SmartHomeControl/module.php'
with open(file, 'rb') as f:
    content = f.read().decode('utf-8')

replacements = {
    'Ã¤': 'ä',
    'Ã¶': 'ö',
    'Ã¼': 'ü',
    'ÃŸ': 'ß',
    'Ã„': 'Ä',
    'Ã–': 'Ö',
    'Ãœ': 'Ü',
    'â‚¬': '€',
    'Â³': '³',
    'Â²': '²'
}
for k, v in replacements.items():
    content = content.replace(k, v)

with open(file, 'wb') as f:
    f.write(content.encode('utf-8'))