# Testy

W środowisku bez MySQL uruchamiane są testy usług, parserów, manifestu wtyczki i generowania PDF:

```bash
php bin/lint.php
php tests/run.php
node --check chrome_extension/background.js
node --check chrome_extension/content_krz.js
node --check chrome_extension/options.js
node --check chrome_extension/popup.js
```

Pełny test integracyjny wymaga MySQL i realnej sesji KRZ w Chrome.
