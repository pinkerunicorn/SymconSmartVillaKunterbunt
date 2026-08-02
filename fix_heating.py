import os

file = 'SmartHomeHeating/module.php'
with open(file, 'r', encoding='utf-8') as f:
    content = f.read()

content = content.replace('IPS_Sleep(500);', 'usleep(200000);')

with open(file, 'w', encoding='utf-8', newline='\n') as f:
    f.write(content)
