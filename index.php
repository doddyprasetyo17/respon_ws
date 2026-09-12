<?php

// Dashboard-nya ada di subfolder curl/. Redirect ini dipertahankan supaya
// bookmark dan shortcut lama di komputer IT tetap berfungsi.

header('Location: curl/index.php', true, 302);
exit;
