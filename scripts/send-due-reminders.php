<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$mode=$argv[1]??'--dry-run';
if(!in_array($mode,['--dry-run','--run'],true))exit("Usage: php scripts/send-due-reminders.php [--dry-run|--run]\n");
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/sms-reminders.php';
try {echo json_encode(runDueReminders($pdo,$mode!=='--run'),JSON_THROW_ON_ERROR),PHP_EOL;}
catch(Throwable $e){fwrite(STDERR,"Reminder runner failed; inspect private server logs/settings. No automatic retry.\n");exit(1);}
