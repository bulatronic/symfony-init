# Symfony Initializr

[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?style=flat&logo=php)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/Symfony-7.4-000000?style=flat&logo=symfony)](https://symfony.com/)
[![FrankenPHP](https://img.shields.io/badge/FrankenPHP-1-2496ED?style=flat)](https://frankenphp.dev/)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?style=flat&logo=docker)](https://www.docker.com/)

Веб-сервис для генерации готовых Symfony-проектов с Docker-конфигурацией. Пользователь выбирает параметры (имя проекта, версию PHP, сервер приложений, версию Symfony) и получает архив ZIP с развёрнутым проектом.

<p align="center">
  <img src="docs/screenshot.png" alt="Главная страница Symfony Initializr" />
</p>

---

## Стек технологий

| Компонент | Версия |
|-----------|--------|
| PHP | 8.5 |
| Symfony | 7.4 |
| Сервер приложений | FrankenPHP |
| Стиль кода | PHP CS Fixer (@Symfony) |

Приложение запускается в Docker-контейнере на базе FrankenPHP (PHP 8.5).

---

## Возможности

- **Выбор параметров**: имя проекта, версия PHP (8.2–8.5), сервер приложений (PHP-FPM или FrankenPHP), версия Symfony (7.4 LTS, 8.0).
- **Генерация проекта**: создание скелета Symfony через `composer create-project`, подстановка Dockerfile, docker-compose и конфигурации веб-сервера (Nginx для FPM, Caddyfile для FrankenPHP).
- **Скачивание**: выдача готового проекта в виде ZIP-архива.

---

## Запуск

```bash
docker compose up -d --build
```

Сервис будет доступен по адресу: http://localhost:8080

Войти в контейнер:

```bash
docker compose exec frankenphp bash
```

---

## Разработка

Для проверки и автоисправления стиля кода используется [PHP CS Fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer) с правилами `@Symfony`. Конфигурация — `app/.php-cs-fixer.dist.php`. Запуск внутри контейнера (из каталога приложения):

```bash
docker compose exec frankenphp bash
./vendor/bin/php-cs-fixer fix src
```

---

## Возможные улучшения

- **Динамические версии**: подтягивать актуальные версии PHP, Symfony и серверов из внешних источников (API, Packagist и т.п.) вместо хардкода в коде.
- **Производительность**: оптимизировать скорость работы — сейчас генерация и загрузка страницы занимают заметное время.
- **Популярные пакеты**: дать возможность выбирать типовые зависимости (БД, Redis, Messenger, Security и др.) — подставлять нужные расширения в Dockerfile и подтягивать соответствующие бандлы в проект.

---

---

# Symfony Initializr

[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?style=flat&logo=php)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/Symfony-7.4-000000?style=flat&logo=symfony)](https://symfony.com/)
[![FrankenPHP](https://img.shields.io/badge/FrankenPHP-1-2496ED?style=flat)](https://frankenphp.dev/)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?style=flat&logo=docker)](https://www.docker.com/)

A web service for generating ready-to-use Symfony projects with Docker configuration. The user selects options (project name, PHP version, application server, Symfony version) and receives a ZIP archive with a bootstrapped project.

<p align="center">
  <img src="docs/screenshot.png" alt="Symfony Initializr main page" />
</p>

---

## Tech stack

| Component | Version |
|-----------|---------|
| PHP | 8.5 |
| Symfony | 7.4 |
| Application server | FrankenPHP |
| Code style | PHP CS Fixer (@Symfony) |

The application runs in a Docker container based on FrankenPHP (PHP 8.5).

---

## Features

- **Parameter selection**: project name, PHP version (8.2–8.5), application server (PHP-FPM or FrankenPHP), Symfony version (7.4 LTS, 8.0).
- **Project generation**: creating a Symfony skeleton via `composer create-project`, injecting Dockerfile, docker-compose, and web server config (Nginx for FPM, Caddyfile for FrankenPHP).
- **Download**: delivery of the generated project as a ZIP archive.

---

## Running

```bash
docker compose up -d --build
```

The service will be available at: http://localhost:8080

Enter the container:

```bash
docker compose exec frankenphp bash
```

---

## Development

Code style is enforced with [PHP CS Fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer) using the `@Symfony` rule set. Config: `app/.php-cs-fixer.dist.php`. Run inside the container (from the app directory):

```bash
docker compose exec frankenphp bash
./vendor/bin/php-cs-fixer fix src
```

---

## Future improvements

- **Dynamic versions**: fetch current PHP, Symfony, and server versions from external sources (API, Packagist, etc.) instead of hardcoding them.
- **Performance**: improve response time — generation and page load are currently relatively slow.
- **Popular packages**: allow selecting common dependencies (DB, Redis, Messenger, Security, etc.) — inject required extensions into the Dockerfile and add the corresponding bundles to the project.
