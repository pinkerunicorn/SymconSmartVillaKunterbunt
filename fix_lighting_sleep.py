import os

file = 'SmartHomeLighting/module.php'
with open(file, 'r', encoding='utf-8') as f:
    content = f.read()

content = content.replace('IPS_Sleep(100);', 'usleep(100000);')

with open(file, 'w', encoding='utf-8', newline='\n') as f:
    f.write(content)
