<?php
// "Asset Checkout" आता "Issue / Return Register" (allocations/) मध्ये — जुने Bookmarks/सूचना-Links चालू राहावेत म्हणून कायमचा Redirect
header('Location: ../allocations/index.php?view=loans', true, 301);
exit;
