<?php
// =========================================================================
// AGENDOU - Integração com Encurtador Oficial 4U.IA.BR
// Gera e gerencia links curtos das barbearias (ex: 4u.ia.br/pedromendes)
// =========================================================================

class UrlShortenerService {
    private static ?string $enginePath = null;

    private static function getEngine(): ?string {
        if (self::$enginePath === null) {
            $possible = [
                dirname(__DIR__, 4) . '/shortener_engine.php',
                $_SERVER['DOCUMENT_ROOT'] . '/shortener_engine.php',
                '/home/fabiano/public_html/shortener_engine.php'
            ];
            foreach ($possible as $p) {
                if (file_exists($p)) {
                    self::$enginePath = $p;
                    break;
                }
            }
        }
        return self::$enginePath;
    }

    /**
     * Garante que o link curto da barbearia esteja ativo no encurtador
     */
    public static function ensureTenantShortLink(array $tenant): string {
        $slug = trim($tenant['slug'] ?? '');
        $name = trim($tenant['name'] ?? 'Barbearia');
        if (empty($slug)) {
            return "https://4u.ia.br/app/agendou/";
        }

        $longUrl = "https://4u.ia.br/app/agendou/?slug=" . urlencode($slug);
        $title = $name . " • Agendamento Online";

        $engine = self::getEngine();
        if ($engine) {
            require_once $engine;
            try {
                $res = UrlShortener::shorten($longUrl, $slug, 'agendou', $title);
                return $res['short_url'];
            } catch (Throwable $e) {
                // Se o slug direto for reservado ou colidir, tenta com prefixo
                try {
                    $res = UrlShortener::shorten($longUrl, 'a-' . $slug, 'agendou', $title);
                    return $res['short_url'];
                } catch (Throwable $e2) {
                    return "https://4u.ia.br/" . $slug;
                }
            }
        }

        return "https://4u.ia.br/" . $slug;
    }

    /**
     * Retorna a URL curta canônica da barbearia
     */
    public static function getShortUrl(string $slug): string {
        return "https://4u.ia.br/" . trim($slug);
    }

    /**
     * Retorna estatísticas de cliques do link curto da barbearia
     */
    public static function getStats(string $slug): array {
        $engine = self::getEngine();
        if ($engine) {
            require_once $engine;
            try {
                $stats = UrlShortener::getStats($slug);
                if ($stats) return $stats;
            } catch (Throwable $e) {
                // Ignore
            }
        }
        return [
            'link' => ['clicks' => 0],
            'recent_clicks' => [],
            'devices' => []
        ];
    }
}
