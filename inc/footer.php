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
  </div>
</footer>
</body>
</html>
