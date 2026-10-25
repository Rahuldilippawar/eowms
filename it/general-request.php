<?php
// IT/Internet तक्रार — आता एकत्रित विनंती प्रणालीत (requests/, category = IT).
// जुने Links/Bookmarks चालू राहावेत म्हणून हे पान फक्त तिकडे वळवतं.
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
header('Location: ../requests/create.php?category=it');
exit;
