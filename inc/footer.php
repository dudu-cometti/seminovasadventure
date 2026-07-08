<footer class="site-footer">
  <div class="footer-brand">
    <?= htmlspecialchars($nomeLoja ?? 'Adventure Motos') ?>
  </div>
  <p>
    <?= htmlspecialchars($cidade ?? 'São Silvano - ES') ?> &middot; Atendimento via WhatsApp.
    As imagens, informações e valores são ilustrativos. Reservamo-nos o direito de
    corrigir eventuais erros sem aviso prévio.
  </p>
  <div class="footer-mini">
    © <?= date('Y') ?> <?= htmlspecialchars($nomeLoja ?? 'Adventure Motos') ?>. Todos os direitos reservados.
    <?php if (empty($isLogged)): ?>
      &middot; <a class="footer-admin-link" href="<?= base_url('login.php') ?>">Acesso restrito</a>
    <?php endif; ?>
  </div>
</footer>
</body>
</html>
