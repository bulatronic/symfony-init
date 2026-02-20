# Symfony Initializr

[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?style=flat&logo=php)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/Symfony-7.4-000000?style=flat&logo=symfony)](https://symfony.com/)
[![FrankenPHP](https://img.shields.io/badge/FrankenPHP-1-2496ED?style=flat)](https://frankenphp.dev/)
[![Bootstrap](https://img.shields.io/badge/Bootstrap-5-7952B3?style=flat&logo=bootstrap)](https://getbootstrap.com/)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?style=flat&logo=docker)](https://www.docker.com/)

**Live:** [symfony-init.dev](https://symfony-init.dev)

**Язык / Language:** [Русский](#russian) · [English](#english)

---

<a id="russian"></a>

## Веб-сервис генерации Symfony-проектов с Docker

Выберите параметры проекта и получите ZIP с готовым Symfony-приложением под Docker. Запуск: `docker compose up`.

**Скриншоты интерфейса**

<p style="text-align: center">
  <a href="docs/screenshot.png"><img src="docs/screenshot.png" alt="Главная страница Symfony Initializr" width="1434" /></a>
</p>

---

## Возможности

- **Параметры**: имя проекта, PHP, сервер (PHP-FPM + Nginx или FrankenPHP), версия Symfony (LTS и текущие - с symfony.com).
- **База данных**: без БД, PostgreSQL, MySQL, MariaDB, SQLite. Связка с чекбоксом Doctrine ORM: выбор БД включает ORM, выбор ORM подставляет PostgreSQL при «без БД». В Docker - образы на Alpine где есть (postgres, redis, memcached, rabbitmq).
- **Кеш**: селект None / Redis / Memcached. В проект добавляются контейнер, PHP-расширение и `CACHE_DSN` в `.env`.
- **Расширения**: Doctrine ORM, Security, Mailer, Messenger, Validator, Serializer, API Platform, HTTP Client, Nelmio API Doc. Зависимости подставляются автоматически (API Platform → ORM + Serializer + Nelmio; RabbitMQ → Messenger).
- **Message broker**: опция RabbitMQ - контейнер RabbitMQ с management и `MESSENGER_TRANSPORT_DSN` в `.env`.
- **Генерация**: скелет через `composer create-project`, подстановка Dockerfile, docker-compose, конфиг веб-сервера (Nginx / Caddyfile). Рецепты Flex не дублируют наши сервисы (`SYMFONY_SKIP_DOCKER=1`, очистка блоков рецептов в compose).
- **Скачивание**: кнопка блокируется до ответа, архив через fetch. При лимите - сообщение и время повтора.
- **Кеширование**: по комбинации параметров (PHP, сервер, Symfony, расширения, БД, кеш, RabbitMQ). Кеш в `var/share/` (Symfony 7.4+).
- **Лимит**: 30 запросов в час на IP.

---

## Безопасность и прозрачность

Проект открыт: вы можете просмотреть [исходный код генератора](app/src/) и логику сборки архива до использования. Сгенерированный ZIP содержит только стандартный скелет Symfony, зависимости из Packagist и добавленные Docker/конфиги - без скрытого кода. Перед развёртыванием рекомендуем просмотреть содержимое архива.

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

Стиль кода проверяется и исправляется с помощью [PHP CS Fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer) (правила `@Symfony`). Конфигурация - `app/.php-cs-fixer.dist.php`. Запуск внутри контейнера (из каталога приложения):

```bash
docker compose exec frankenphp bash
./vendor/bin/php-cs-fixer fix src
```

Прогрев кеша версий PHP/Symfony и сгенерированных проектов (по желанию):

```bash
# только популярные конфигурации (по умолчанию)
php bin/console app:warm-cache
php bin/console app:warm-cache --popular-only

# все базовые комбинации PHP × Symfony × сервер, без расширений/БД (дольше)
php bin/console app:warm-cache --all-base
```

Сброс кеша сгенерированных проектов (пул cache.app):

```bash
php bin/console cache:pool:clear cache.app
```

### Тесты

```bash
php vendor/bin/phpunit --configuration phpunit.dist.xml
```

Покрыты юнит-тестами:
- `ProjectConfigFactory` - бизнес-правила (ORM ↔ БД, RabbitMQ → Messenger, нормализация имени проекта)
- `ValidGeneratorOptionValidator` - кастомный constraint для доменной валидации параметров запроса

---

## Участие в разработке

Исправления, идеи и доработки приветствуются: Issues и Pull Request в репозитории проекта.

---

<a id="english"></a>

## Web Service for Generating Symfony Projects with Docker

Pick project options and get a ZIP with a ready-to-run Symfony app for Docker. Run with `docker compose up`.

**Interface screenshots**

<p style="text-align: center">
  <a href="docs/screenshot.png"><img src="docs/screenshot.png" alt="Symfony Initializr main page" width="1434" /></a>
</p>

---

## Features

- **Parameters**: project name, PHP version, server (PHP-FPM + Nginx or FrankenPHP), Symfony version (LTS and current, from symfony.com).
- **Database**: none, PostgreSQL, MySQL, MariaDB, SQLite. UI syncs with Doctrine ORM: choosing a DB checks ORM; checking ORM selects PostgreSQL when DB is none. Docker uses Alpine-based images where available (postgres, redis, memcached, rabbitmq).
- **Cache**: selector None / Redis / Memcached. Adds the container, PHP extension, and `CACHE_DSN` in `.env`.
- **Extensions**: Doctrine ORM, Security, Mailer, Messenger, Validator, Serializer, API Platform, HTTP Client, Nelmio API Doc. Dependencies are auto-selected (API Platform → ORM + Serializer + Nelmio; RabbitMQ → Messenger).
- **Message broker**: RabbitMQ option adds the RabbitMQ container with management UI and `MESSENGER_TRANSPORT_DSN` in `.env`.
- **Generation**: skeleton via `composer create-project`, Dockerfile and docker-compose injection, web server config (Nginx / Caddyfile). Flex recipes do not duplicate our services (`SYMFONY_SKIP_DOCKER=1`, recipe blocks stripped from compose).
- **Download**: button disabled until response; archive via fetch. Rate limit shows message and retry time.
- **Caching**: by parameter set (PHP, server, Symfony, extensions, database, cache, RabbitMQ). Cache in `var/share/` (Symfony 7.4+).
- **Rate limit**: 30 requests per hour per IP.

---

## Trust & transparency

The project is open source: you can inspect the [generator source code](app/src/) and how the archive is built before using it. The generated ZIP contains only the standard Symfony skeleton, dependencies from Packagist, and the added Docker/config files - no hidden code. We recommend reviewing the archive contents before deploying.

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

To warm the PHP/Symfony version cache and generated project cache (optional):

```bash
# popular configurations only (default)
php bin/console app:warm-cache
php bin/console app:warm-cache --popular-only

# all base PHP × Symfony × server combinations, no extensions/DB (slower)
php bin/console app:warm-cache --all-base
```

To clear the generated project cache (cache.app pool):

```bash
php bin/console cache:pool:clear cache.app
```

### Tests

```bash
php vendor/bin/phpunit --configuration phpunit.dist.xml
```

Unit tests cover:
- `ProjectConfigFactory` - business rules (ORM ↔ DB, RabbitMQ → Messenger, project name normalization)
- `ValidGeneratorOptionValidator` - custom constraint for domain validation of request parameters

---

## Contributing

Fixes, ideas and improvements are welcome via Issues and Pull Requests in the project repository.