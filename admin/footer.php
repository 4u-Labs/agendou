            </main>

            <!-- Admin Footer -->
            <footer class="admin-footer">
                <p>&copy; 2026 AGENDOU!! SaaS • Todos os direitos reservados • 4U.IA.BR</p>
            </footer>
        </div>
    </div>

    <?php include_once __DIR__ . '/tutorial_modal.php'; ?>

    <script src="/app/agendou/public/js/pwa-installer.js"></script>
    <script>
        function toggleAdminSidebar() {
            document.getElementById('adminSidebar')?.classList.toggle('open');
        }
    </script>
</body>
</html>
