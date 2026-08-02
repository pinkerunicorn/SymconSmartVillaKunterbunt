import os

file = 'SmartNotifier/module.php'
with open(file, 'r', encoding='utf-8') as f:
    content = f.read()

content = content.replace('VESTA_PushAlert', 'VESTAG_PushAlert')

with open(file, 'w', encoding='utf-8', newline='\n') as f:
    f.write(content)
