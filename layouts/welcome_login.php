<?php
// Форма логина (аналогично layouts/welcome_login.php в примере)
$err = $err ?? '';
?>


<div class="wrap">
	<div class="card">
		<form method="post" action="">
			<?php if ($err !== ''): ?>
				<div class="err"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
			<?php endif; ?>

			<label class="muted">Логин</label>
			<input name="login" value="<?= isset($_POST['login']) ? htmlspecialchars((string)$_POST['login'], ENT_QUOTES, 'UTF-8') : '' ?>" autocomplete="username" />
			<div style="height:10px"></div>

			<label class="muted">Пароль</label>
			<input name="pass" type="password" autocomplete="current-password" />
			<div style="height:14px"></div>

			<button type="submit">Войти</button>
		</form>

		<div class="footer muted">
			Регистрация — <a href="https://max.ru/u/f9LHodD0cOKM7H1PPEzfqd5jXFnTbq_cbLe8iTDK_dssjQW_wLsCxWreH5o" target="_blank" rel="noreferrer">свяжитесь с администратором в Max</a>.
		</div>
		<div class="footer muted">
			Почитать и посмотреть о системе: <a href="https://vkvideo.ru/playlist/-236232859_1" target="_blank" rel="noreferrer">можно тут</a>.
		</div>
	</div>
</div>

