<?php
require_once __DIR__.'/includes/bootstrap.php';
$message='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$now=time();$window=$_SESSION['_reset_limiter']??['started'=>$now,'count'=>0];if(!is_array($window)||$now-(int)($window['started']??0)>3600)$window=['started'=>$now,'count'=>0];$window['count']=(int)($window['count']??0)+1;$_SESSION['_reset_limiter']=$window;
 $email=strtolower(trim((string)($_POST['email']??'')));
 if($window['count']<=5&&filter_var($email,FILTER_VALIDATE_EMAIL)){
  $st=$GLOBALS['pdo']->prepare('SELECT id,email FROM users WHERE email=? AND is_active=1 LIMIT 1');$st->execute([$email]);$user=$st->fetch();
  if($user){$recent=$GLOBALS['pdo']->prepare('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)');$recent->execute([$user['id']]);if((int)$recent->fetchColumn()<3){$GLOBALS['pdo']->prepare('UPDATE password_reset_tokens SET used_at=COALESCE(used_at,NOW()) WHERE user_id=? AND used_at IS NULL')->execute([$user['id']]);$token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$GLOBALS['pdo']->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))')->execute([$user['id'],$hash]);$resetUrl=url('reset-password.php?token='.rawurlencode($token));$from=getenv('MAIL_FROM')?:'no-reply@localhost';if(!filter_var($from,FILTER_VALIDATE_EMAIL))$from='no-reply@localhost';$headers='From: '.$from."\r\n".'Content-Type: text/plain; charset=UTF-8';$mailOk=@mail($email,'Reset password Datanix',"Buka tautan ini dalam 30 menit untuk mengganti password Anda:\n\n".$resetUrl."\n\nJika Anda tidak meminta reset, abaikan email ini.",$headers);if(!$mailOk)error_log('Datanix reset mail transport is not configured; set MAIL_FROM and the deployment mail service.');audit_log('password.reset_requested','user',(int)$user['id']);}}
 }
 $message='Jika alamat terdaftar, instruksi reset telah dikirim. Periksa email. Untuk deployment lokal, konfigurasi transport email terlebih dahulu; respons ini tidak mengungkap apakah akun ada.';
}
require_once __DIR__.'/includes/header.php';$layout='marketing';datanix_header('Reset password','',$layout);
?>
<div class="auth-page"><div class="form-card"><a class="brand-lockup mb-4" href="<?=e(url('index.php'))?>"><img src="<?=e(url('assets/img/datanix-mark.svg'))?>" width="34" height="34" alt=""><span>datanix</span></a><h1>Atur ulang password.</h1><p class="small-muted">Tautan satu kali berlaku 30 menit. Respons selalu sama agar email akun tidak dapat ditebak.</p><?php if($message):?><div class="alert alert-info"><?=e($message)?></div><?php endif;?><form method="post"><?=csrf_field()?><div class="mb-4"><label class="form-label" for="email">Email akun</label><input class="form-control" id="email" type="email" name="email" autocomplete="email" maxlength="190" required></div><button class="btn btn-primary w-100">Kirim tautan</button></form><p class="small-muted text-center mt-4"><a href="<?=e(url('login.php'))?>">Kembali ke masuk</a></p></div></div>
<?php require __DIR__.'/includes/footer.php';?>
