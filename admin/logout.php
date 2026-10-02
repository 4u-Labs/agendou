<?php
session_start();
session_destroy();
header("Location: /app/agendou/admin/login.php");
exit;
