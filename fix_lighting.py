import os

lighting_file = 'SmartActiveLighting/module.php'
with open(lighting_file, 'r', encoding='utf-8') as f:
    content = f.read()

content = content.replace(
    'foreach ($this->GetReferenceList() as $refID) {\n        }',
    'foreach ($this->GetReferenceList() as $refID) {\n            $this->UnregisterReference($refID);\n        }'
)
content = content.replace(
    'foreach ($this->GetReferenceList() as $refID) {\r\n        }',
    'foreach ($this->GetReferenceList() as $refID) {\n            $this->UnregisterReference($refID);\n        }'
)

with open(lighting_file, 'w', encoding='utf-8', newline='\n') as f:
    f.write(content)
