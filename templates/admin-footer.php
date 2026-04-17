  </div><!-- .content-body -->
  <footer class="admin-footer">
    <span><?= e(APP_NAME) ?> <span class="version-tag">v<?= e(APP_VERSION) ?></span></span>
    <span class="db-status db-status--<?php try { db(); echo 'ok'; } catch(Throwable $e){ echo 'error'; } ?>">
      <span class="db-status__dot"></span>
      <?php try { db(); echo 'Database Connected'; } catch(Throwable $e){ echo 'Database Disconnected'; } ?>
    </span>
  </footer>
</div><!-- .main-content -->
<script src="<?= e(BASE_URL) ?>/assets/js/app.js"></script>
<?php if (!empty($extraJs)): ?><script src="<?= e(BASE_URL . $extraJs) ?>"></script><?php endif; ?>
</body>
</html>
