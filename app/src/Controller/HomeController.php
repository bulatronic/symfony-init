<?php

declare(strict_types=1);

namespace App\Controller;

use App\GeneratorOptions;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HomeController
{
    public function __construct(
        private GeneratorOptions $options,
    ) {
    }

    #[Route(path: '/', name: 'app_home', methods: ['GET'])]
    public function __invoke(): Response
    {
        $phpVersions = $this->options->phpVersions;
        $servers = $this->options->servers;
        $symfonyVersions = $this->options->symfonyVersions;

        $html = $this->renderPage($phpVersions, $servers, $symfonyVersions);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @param list<string>          $phpVersions
     * @param array<string, string> $servers
     * @param array<string, string> $symfonyVersions
     */
    private function renderPage(array $phpVersions, array $servers, array $symfonyVersions): string
    {
        $phpOptions = implode('', array_map(fn (string $v) => sprintf('<option value="%s">PHP %s</option>', htmlspecialchars($v), htmlspecialchars($v)), $phpVersions));
        $serverOptions = implode('', array_map(fn (string $k, string $v) => sprintf('<option value="%s">%s</option>', htmlspecialchars($k), htmlspecialchars($v)), array_keys($servers), array_values($servers)));
        $symfonyOptions = implode('', array_map(fn (string $k, string $v) => sprintf('<option value="%s">%s</option>', htmlspecialchars($k), htmlspecialchars($v)), array_keys($symfonyVersions), array_values($symfonyVersions)));

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Symfony Initializr</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
            </head>
            <body class="bg-light d-flex flex-column min-vh-100">
                <nav class="navbar navbar-expand bg-white border-bottom mb-4">
                    <div class="container">
                        <a class="navbar-brand d-flex align-items-center gap-2 text-dark" href="/">
                            <img src="/symfony-original.svg" alt="Symfony" height="32" width="32" class="d-inline-block">
                            <span>Symfony Initializr</span>
                        </a>
                    </div>
                </nav>
                <main class="container py-4 flex-grow-1">
                    <div class="row justify-content-center">
                        <div class="col-md-8 col-lg-6">
                            <div class="card shadow-sm">
                                <div class="card-body p-4">
                                    <p class="text-muted text-center mb-4 lead">Choose options and generate a Symfony project with Docker.</p>
                                    <form action="/generate" method="get" id="form">
                                        <div class="mb-3">
                                            <label for="name" class="form-label">Project name</label>
                                            <input type="text" name="name" id="name" class="form-control" placeholder="demo-symfony" pattern="[a-zA-Z0-9_-]+" maxlength="64" title="Letters, numbers, hyphen and underscore only">
                                        </div>
                                        <div class="mb-3">
                                            <label for="php" class="form-label">PHP version</label>
                                            <select name="php" id="php" class="form-select" required>
                                                {$phpOptions}
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label for="server" class="form-label">Application server</label>
                                            <select name="server" id="server" class="form-select" required>
                                                {$serverOptions}
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label for="symfony" class="form-label">Symfony version</label>
                                            <select name="symfony" id="symfony" class="form-select" required>
                                                {$symfonyOptions}
                                            </select>
                                        </div>
                                        <button type="submit" id="btn" class="btn btn-dark">Generate project</button>
                                        <span class="form-text d-block mt-2" id="hint"></span>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>
                <footer class="mt-auto py-3 bg-white border-top">
                    <div class="container text-center text-muted small">
                        <a href="https://github.com/symfony/symfony" class="text-decoration-none text-muted d-inline-flex align-items-center gap-1" target="_blank" rel="noopener">
                        <img src="https://github.githubassets.com/images/modules/logos_page/GitHub-Mark.png" alt="GitHub" width="20" height="20">
                        <span>GitHub</span>
                    </a>
                    </div>
                </footer>
                <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
                <script>
                    document.getElementById('form').addEventListener('submit', function(e) {
                        var btn = document.getElementById('btn');
                        var hint = document.getElementById('hint');
                        btn.disabled = true;
                        hint.textContent = 'Generating…';
                        window.location.href = '/generate?' + new URLSearchParams(new FormData(this)).toString();
                        setTimeout(function() { btn.disabled = false; hint.textContent = ''; }, 5000);
                    });
                </script>
            </body>
            </html>
        HTML;
    }
}
