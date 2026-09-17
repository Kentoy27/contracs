        </div>
<?php
$is_pjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'fetch');
if (!$is_pjax) {
?>
    </div>
    <!-- [ Footer ] start -->
    <footer class="footer text-center">
            <p class="fs-11 text-muted fw-medium text-uppercase mb-0 copyright">
                <span>Copyright ©</span>
                <script>
                    document.write(new Date().getFullYear());
                </script>
                <span class="ms-2">•</span>
                <a href="credits" class="text-muted text-decoration-none ms-2">Credits</a>
            </p>
            </div>
    </footer>
</main>
<?php require __DIR__ . '/scripts.php'; ?>
    </body>

    </html>
<?php } ?>
