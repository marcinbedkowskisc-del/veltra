# VELTRA 2.0 — ocena advisory Composer przed STAGING

**Wynik: PARTIAL — nie zatwierdzać jeszcze STAGING.** Audyt odtworzył 7 advisory w 4 pakietach. Analiza kodu nie wykazała większości warunków potrzebnych do ich wykorzystania, ale repozytorium nie zawiera konfiguracji hostowanego STAGING ani jego reverse proxy. Przed STAGING należy zaktualizować podatne pakiety i potwierdzić konfigurację ingressu.

## Zakres i metoda

- Branch `main`, HEAD `cb6f00d047414966a39f20020669e4de8c2b22f8`; przed dodaniem tego raportu worktree był czysty.
- `composer audit --format=json` uruchomiono w efemerycznym kontenerze opartym na istniejącym obrazie backendu. Aktualny katalog `backend/` zamontowano tylko do odczytu; Composer nie jest zainstalowany na hoście. Nie połączono kontenera z bazą. Audyt zakończył się kodem `1` z powodu wykrytych advisory.
- Wersje z bieżącego `composer.lock` i `vendor/composer/installed.json`: Laravel `13.39.0`, Symfony `http-foundation` `8.1.10`, `http-kernel` `8.1.10`, `routing` `8.1.10`.
- Działające kontenery backendowe mają inny hash `composer.lock`; nie użyto ich starych zależności do ustalenia wyniku.
- Severity podano według źródeł advisory. Composer zwraca `severity: null` dla sześciu wpisów Symfony z feedu FriendsOfPHP, więc tam ocena pochodzi z advisory Symfony/GitHub/OSV, nie z pola Composer.

## Ustalenia

| Pakiet / wersja | Advisory (Composer; CVE/GHSA) | Severity | Istotność dla obecnego kodu i STAGING | Klasyfikacja |
|---|---|---|---|---|
| `laravel/framework` `13.39.0` | `PKSA-yxbx-3c9s-cjt9`; CVE-2026-128083 / GHSA-63hm-g96r-2m2q | Low | Błąd wymaga osiągalnego `Concurrency::run()` oraz zadania wypisującego specjalnie spreparowane dane użytkownika na stdout. W `app/` i `routes/` nie znaleziono wywołań `Concurrency::run`; obecne zadanie kolejki nie spełnia tego warunku. | **ACCEPTABLE TEMPORARILY WITH DOCUMENTED MITIGATION** — nie dodawać takiego przepływu przed poprawką. |
| `symfony/http-foundation` `8.1.10` | `PKSA-nxjs-s6hn-vb6m`; CVE-2026-121619 / GHSA-xg2q-4rrq-657q | Moderate; CVSS 4.0: 6.3 | Dotyczy użycia skrótu `private_ranges` jako listy zaufanych proxy. W repozytorium nie znaleziono takiej konfiguracji ani wywołania `trustProxies()`. Nie ma jednak konfiguracji STAGING, więc nie można sprawdzić, czy wdrożeniowy ingress ustawi zaufanie proxy w ten sposób. W przypadku ekspozycji możliwe jest podszycie IP/hosta/schematu. | **BLOCKER BEFORE STAGING** — przed uruchomieniem potwierdzić jawne, ograniczone adresy zaufanych proxy i zaktualizować pakiet. |
| `symfony/http-foundation` `8.1.10` | `PKSA-24ph-xkrh-y79j`; CVE-2026-118514 | Medium; CVSS 4.0: 6.3 | Bypass dotyczy Symfony `RequestMatcher`/`MethodRequestMatcher` oraz reguł Symfony Security ograniczonych do `GET`. Projekt używa Laravel middleware/autoryzacji; nie znaleziono Symfony SecurityBundle, matcherów ani reguł `access_control`. Nie wykazano ścieżki, w której `HEAD` omija kontrolę dostępu aplikacji. | **ACCEPTABLE TEMPORARILY WITH DOCUMENTED MITIGATION** — nie wprowadzać metodowych matcherów Symfony przed poprawką. |
| `symfony/http-foundation` `8.1.10` | `PKSA-pr6q-2w5s-kzzf`; GHSA-3h27-hjv7-fqjx | Low | Może ominąć reguły ścieżki egzekwowane przed PHP (proxy/WAF/CDN), gdy nazwa skryptu front controllera jest prefiksem ścieżki. Kod aplikacji nie polega na takich regułach; konfiguracji STAGING/ingressu brak. | **SHOULD FIX BEFORE STAGING** — nie traktować filtrów ścieżek przed PHP jako granicy autoryzacji; ingress wymaga osobnego potwierdzenia. |
| `symfony/http-foundation` `8.1.10` | `PKSA-b2gg-vk1g-6d5q`; GHSA-5mx8-mwrp-5693 | Moderate | Wymaga określonej obsługi front controllera (`SCRIPT_NAME`/rewrites); spreparowana ścieżka może skierować generowane URL-e na host atakującego. Aplikacja jest API/SPA, a [IdentityLink](backend/app/Mail/IdentityLink.php) buduje linki z konfigurowanego `FRONTEND_URL`, nie z hosta żądania. Ustawień front controllera STAGING nie ma w repozytorium. | **SHOULD FIX BEFORE STAGING** — potwierdzić konfigurację serwera i poprawić zależność przed wystawieniem aplikacji. |
| `symfony/http-kernel` `8.1.10` | `PKSA-3gxv-7xhq-mgmz`; CVE-2026-123339 / GHSA-wg5q-m2ww-f3c9 | Low | Wymaga inline subrequest/fragmentu oraz żądania docierającego z loopback proxy. Nie znaleziono Twig/ESI, renderowanych inline fragmentów ani `render()` w aplikacji; repozytorium nie definiuje proxy STAGING. | **ACCEPTABLE TEMPORARILY WITH DOCUMENTED MITIGATION** — nie dodawać inline fragmentów i sprawdzić adres proxy przed wdrożeniem. |
| `symfony/routing` `8.1.10` | `PKSA-y8c5-1b7f-r1gj`; CVE-2026-118516 | Medium; CVSS 4.0: 6.3 | Bypass reguł ścieżki wymaga tras z placeholderami hosta. Nie znaleziono `Route::domain()` ani hostowych tras Symfony; aplikacja nie używa Symfony Security `access_control`/firewall. | **ACCEPTABLE TEMPORARILY WITH DOCUMENTED MITIGATION** — nie dodawać hostowych tras chronionych osobnymi regułami ścieżki przed poprawką. |

## Ocena ryzyka STAGING

W repozytorium nie znaleziono hostowanego STAGING ani jego publicznego URL; potwierdza to również [inwentaryzacja środowisk](VELTRA_TEST_ENVIRONMENT_AND_SUPER_ADMIN_AUDIT.md). Dostępny Compose opisuje środowisko lokalne (`APP_ENV=local`, `APP_DEBUG=false`, `APP_URL=http://localhost`) i nie potwierdza konfiguracji TLS, proxy, hostów, `SCRIPT_NAME` ani filtrów ingressu dla planowanego STAGING.

Najpoważniejsze ryzyko warunkowe dotyczy zaufania nagłówkom proxy (CVE-2026-121619), a także filtrów ścieżek wykonywanych przed PHP (GHSA-3h27) i generowania URL-i przy nietypowym front controllerze (GHSA-5mx8). Nie ma dowodu, że obecna aplikacja używa podatnych ścieżek, ale brak konfiguracji STAGING uniemożliwia uznanie ich za nieistotne dla planowanego środowiska. Wymagania uwierzytelniania i autoryzacji Laravel pozostają w kodzie; warunkowe problemy proxy nie są podstawą do zastępowania ani obchodzenia tych kontroli.

## Minimalny plan remediacji

1. Przed STAGING zaktualizować lockfile w obrębie obecnych głównych wersji: Laravel do `13.40.0+`, a Symfony `http-foundation`, `http-kernel` i `routing` do `8.1.11+`. Ograniczenia z `composer.json` (`laravel/framework: ^13.17`; Laravel dopuszcza Symfony `^8.0`) pozwalają na te poprawki bez major upgrade. Proponowane polecenie do osobnego, zatwierdzonego etapu naprawczego: `composer update laravel/framework symfony/http-foundation symfony/http-kernel symfony/routing -W`.
2. Przejrzeć lockfile, `composer validate` i ponowić `composer audit`; wymagane zero nierozwiązanych advisory w tych czterech pakietach. Odtworzyć obraz z nowym lockfile i wyczyścić/zregenerować cache tras, ponieważ poprawka Symfony Routing obejmuje kompilowany matcher.
3. Przed uruchomieniem STAGING ustalić ingress i PHP front controller: ustawić wyłącznie konkretne adresy zaufanych proxy (bez skrótu `private_ranges`), ograniczyć hosty, wymusić HTTPS i nie polegać na zewnętrznych filtrach ścieżek jako kontroli dostępu. Sprawdzić generowane linki konta/resetu hasła pod publicznym hostem.
4. W osobnym etapie wykonać regresję backendu oraz testy dla `HEAD`/GET przy zachowaniu autoryzacji, host/path routing i linków konta. To zadanie było wyłącznie oceną; niczego nie aktualizowano ani nie wdrażano.

## Stan końcowy

- Nie zmieniono `composer.json`, `composer.lock`, kodu ani baz danych.
- Nie wykonano testów aplikacji, aktualizacji zależności, commitu, pushu, wdrożenia ani aktywacji capabilities/readiness.
- Raport jest jedynym nowym plikiem w repozytorium; po jego utworzeniu oczekiwany status Git to `?? VELTRA_PRE_STAGING_COMPOSER_SECURITY_ASSESSMENT.md`.
- Folder `RAPORTY_COPILOTA` był dostępny w zalogowanym Google Drive, ale upload raportu nie powiódł się: selektor plików przekształcił ścieżkę Linux `/home/...` na nieistniejącą ścieżkę Windows `C:\home\...` (`ENOENT`). Zgodnie z instrukcją repozytorium nie ponawiano znanej problematycznej ścieżki WSL. Upload i link do pliku na Drive pozostają niezweryfikowane.

## Wyniki remediacji zależności — 2026-11-23

Zaktualizowano wyłącznie cztery podatne pakiety w `backend/composer.lock`; nie zmieniono `composer.json` ani pozostałych zależności:

| Pakiet | Poprzednia wersja | Wersja po poprawce |
|---|---:|---:|
| `laravel/framework` | `13.39.0` | `13.40.0` |
| `symfony/http-foundation` | `8.1.10` | `8.1.11` |
| `symfony/http-kernel` | `8.1.10` | `8.1.11` |
| `symfony/routing` | `8.1.10` | `8.1.11` |

Weryfikacja wykonana po aktualizacji:

- `composer validate --strict`: **PASS**.
- `composer audit --format=json`: **PASS; zero advisory** w pomyślnym uruchomieniu remediacji.
- `composer check-platform-reqs`: **PASS**; środowisko testowe spełnia wymagania platformy.
- Odtworzenie obrazu backend-test z lockfile: **PASS**, instalacja 111 zablokowanych pakietów, bez aktualizacji dodatkowych pakietów i bez major upgrade.
- Izolowana PostgreSQL/Redis/ClamAV infrastruktura: **PASS**; PostgreSQL i Redis healthy, ClamAV odpowiedział `PONG`. Testowe migracje wykonano wyłącznie w izolowanej bazie `veltra_testing`.
- Nowy `PreStagingSecurityRegressionTest`: **PASS**, 4 testy / 6 asercji.
- Pełna backend suite, bezpośrednio przez PHPUnit przy `memory_limit=512M`: **PASS**, 565 testów / 5 557 asercji.
- Dwie wcześniej problematyczne klasy `RawMaterialCompatibilityAssessmentTest` i `RawMaterialDocumentStorageTest`: **PASS** w pełnej suite.
- `vendor/bin/pint --test` dla nowego testu: **PASS**.
- Pełny Pint: **FAIL** z jednym zgłoszonym problemem w niezmienionym `backend/app/Domain/RawMaterials/RawMaterialService.php` (formatowanie `method_chaining_indentation`). Tego niezwiązanego pliku nie zmieniano.
- Powtórzenie Composer validate/audit/platform check po suite nie zakończyło się: kontener nie mógł rozwiązać `repo.packagist.org` (DNS error 6). Nie zmieniono lockfile w tej próbie; audyt bez advisory pochodzi z wcześniejszego udanego uruchomienia.
- Końcowe `git diff --check`: **PASS**.

Test z `artisan test` nie był miarodajny dla limitu pamięci, ponieważ uruchamiany proces potomny PHP miał limit 128 MB i zakończył się OOM. Właściwe pełne uruchomienie wykonano bezpośrednio przez `vendor/bin/phpunit` z `php -d memory_limit=512M`; potwierdzono limit 512 MB w izolowanym kontenerze.

## Wymagania konfiguracji STAGING — PENDING

Repozytorium nadal nie zawiera konfiguracji hostowanego STAGING. Poniższe punkty pozostają **PENDING** i nie są zaliczone jako PASS:

- Wymuszenie HTTPS.
- Jawna lista zaufanych proxy IP/CIDR; bez szerokiego `private_ranges`.
- Ograniczenie do zatwierdzonych nazw hostów.
- Poprawna obsługa nagłówków `Forwarded` / `X-Forwarded-*`.
- Bezpieczny front controller i reguły rewrite URL.
- Niezależne od filtrów ścieżek reverse proxy egzekwowanie autoryzacji.
- Weryfikacja aktywacyjnych i resetujących linków pod rzeczywistym publicznym hostem STAGING. Test jednostkowy potwierdza użycie konfigurowanego `FRONTEND_URL`, ale nie zastępuje testu hostowanego środowiska.

## Zakres zmian i status końcowy

Dokładnie trzy ścieżki należą do bieżącej pracy:

- `backend/composer.lock` — cztery aktualizacje wersji podatnych pakietów.
- `backend/tests/Feature/PreStagingSecurityRegressionTest.php` — focused testy GET/HEAD, tras host-bound, granicy `SCRIPT_NAME` oraz linków aktywacji/resetu.
- `VELTRA_PRE_STAGING_COMPOSER_SECURITY_ASSESSMENT.md` — niniejsze uzupełnienie istniejącego raportu.

Nie zmieniono kodu aplikacji, `composer.json`, readiness/capabilities ani konfiguracji infrastruktury STAGING. Nie wykonano commitu, pushu ani wdrożenia. Nie zmieniano istniejących baz; operacje bazodanowe ograniczały się do izolowanej testowej bazy. Upload Drive nadal nie jest zweryfikowany: dostępne narzędzia sesji nie udostępniły operacji Drive, a znanego błędu WSL `ENOENT` nie ponawiano.

## Źródła advisory

- [Laravel CVE-2026-128083 / GHSA-63hm-g96r-2m2q](https://github.com/laravel/framework/security/advisories/GHSA-63hm-g96r-2m2q)
- [Symfony CVE-2026-121619 / GHSA-xg2q-4rrq-657q](https://github.com/symfony/symfony/security/advisories/GHSA-xg2q-4rrq-657q)
- [Symfony CVE-2026-118514](https://symfony.com/blog/cve-2026-118514-head-requests-bypass-the-methods-option-of-access-control-and-firewalls-incomplete-fix-for-cve-2026-45075)
- [Symfony GHSA-3h27-hjv7-fqjx](https://github.com/symfony/symfony/security/advisories/GHSA-3h27-hjv7-fqjx)
- [Symfony GHSA-5mx8-mwrp-5693](https://github.com/symfony/symfony/security/advisories/GHSA-5mx8-mwrp-5693)
- [Symfony CVE-2026-123339 / GHSA-wg5q-m2ww-f3c9](https://github.com/symfony/symfony/security/advisories/GHSA-wg5q-m2ww-f3c9)
- [Symfony CVE-2026-118516](https://symfony.com/blog/cve-2026-118516-host-placeholders-can-match-the-start-of-the-path-in-compiled-url-matchers-bypassing-path-rules)
- [OSV CVE-2026-118516 severity (CVSS v4)](https://osv.dev/vulnerability/CVE-2026-118516)
