# VELTRA 2.0 — Stage 7A.0.1: Architecture & RBAC Alignment

## Werdykt

**BLOCKED — zaakceptowany baseline nie zawiera wdrożonego hardeningu PostgreSQL ani plików wdrożeniowych obecnych na `main`.** Przygotowanie gałęzi roboczej z tego baseline wymaga decyzji, jak zachować te potrzebne, niezwiązane z zadaniem zmiany. Zgodnie z warunkiem zlecenia prace zatrzymano przed przełączeniem gałęzi i implementacją.

## 1. Stan Git i baseline

- Repozytorium: `/home/veltra/projekty/veltra`.
- Stan początkowy: gałąź `main`, HEAD `8f5e0eaef0603c221b4e65885eb745f6f2a8d299`.
- Zaakceptowany kandydat: `22e3320cc4abafbd5c9db3625299f9f19274ed5d`, gałąź `release/stage6a1-raw-candidate`, osobny worktree `/home/veltra/projekty/veltra-stage6a1-raw-candidate`.
- Kandydat nie jest przodkiem `main`. Wspólny przodek: `3006659240b3a6bdba5b6c0b7b2583b99d21fbe6`. Od niego `main` zawiera commit `8f5e0ea` (`062`), a kandydat zawiera `795356f` i `22e3320`.
- Żądana gałąź `feature/stage7a-rbac-alignment` nie istniała w chwili kontroli; nie została utworzona.
- Nie było śledzonych zmian roboczych. Istniały dwa nieśledzone raporty: `VELTRA_STAGE6A1_UNIFIED_RAW_NAVIGATION_DEPLOYMENT.md` i `VELTRA_STAGE_7A0_RBAC_VERIFICATION_REPORT.md`; oba zachowano.
- Stan końcowy: nadal `main` na tym samym HEAD; jedynym nowym plikiem jest niniejszy raport.

## 2. Dokładna przyczyna blokady

Porównano całe drzewa, nie tylko kod aplikacji. Kandydat zawiera zaakceptowaną jednolitą nawigację RAW, ale nie zawiera wielu zmian w `8f5e0ea`, które nie są jego przodkiem. W szczególności:

1. Kandydat nie ma `docker/postgres/Dockerfile` ani `docker/postgres/.dockerignore`; w `compose.yaml`, `compose.testing.yaml`, `compose.browser-preview.yaml`, `compose.stage2c-security-test.yaml` i `compose.stage3e-b1-test.yaml` wraca do obrazu `postgres:17-alpine` z bind mountem skryptu inicjalizacyjnego. `main` używa obrazu `veltra-postgres:17-alpine` ze skryptem w obrazie. Według [raportu wdrożenia hardeningu](VELTRA_STAGE6A1_POSTGRES_HARDENING_DEPLOYMENT_REPORT.md) ten hardening został wdrożony w lokalnym Stage 6A.1; [raport hardeningu](VELTRA_POSTGRES_DOCKER_HARDENING_REPORT.md) opisuje również znaczenie konfiguracji dla izolowanych testów.
2. Kandydat nie zawiera `Dockerfile.stage6a1-frontend-release`, `compose.stage6a1.release-795356faae84.yaml` oraz licznych śledzonych raportów wdrożeniowych, diagnostycznych i backupowych istniejących na `main`. Zmienia też `README.md`.
3. Różnice kodu aplikacji między oboma HEAD dotyczą m.in. nawigacji RAW; wdrożony kandydat ma commit `22e3320`, opisany w zachowanym nieśledzonym [raporcie wdrożenia](VELTRA_STAGE6A1_UNIFIED_RAW_NAVIGATION_DEPLOYMENT.md).

Commit `main` i jego pliki pozostają zachowane w Git: niczego nie usunięto z historii. Problem polega na tym, że nowa gałąź oparta wyłącznie na kandydacie nie zawierałaby tych zmian w swoim drzewie, a przełączenie bieżącego worktree usunęłoby odpowiadające im śledzone pliki z katalogu roboczego i zastąpiłoby konfiguracje Compose starszym wariantem. Nie rozstrzygnięto samodzielnie, które zmiany przenosić i jak je pogodzić. Zlecenie zabrania automatycznego merge/rebase i nakazuje zatrzymać się, jeśli przygotowanie baseline zagubi wymagane lub niezwiązane prace.

Do kontynuacji potrzebna jest decyzja właściciela o bezpiecznym sposobie połączenia zaakceptowanego kodu RAW z zachowaniem wdrożonego hardeningu, konfiguracji i raportów `main`. Nie zakładano zgody na przeniesienie zmian ani na inną bazę gałęzi.

## 3. Implementacja i testy

- **Nie wykonano:** zmian architektury, pojedynczej roli, ograniczenia liczby central, autoryzacji BOSS lub finansów, integracji RAW, UI ani migracji.
- **Nie utworzono ani nie wykonano migracji**; nie sprawdzano danych operacyjnych pod kątem konfliktów ról, liczby organizacji lub central. Ich zgodność pozostaje niezweryfikowana.
- Nie wykonano testów, builda ani kontroli typów: zatrzymanie nastąpiło na obowiązkowym etapie przygotowania baseline. Nie weryfikowano izolowanej bazy testowej i nie wykonywano testów zapisujących do bazy.
- Faktyczne kontrole: `git status --short --branch`, `git rev-parse HEAD`, `git branch --list ... -vv`, `git worktree list`, `git diff --name-status HEAD 22e3320...` dla całego drzewa, `git diff --stat` i `git diff` wybranych konfiguracji, `git show` commita `8f5e0ea`, `git merge-base` i `git log --left-right`. Odczytano istniejące raporty hardeningu i wdrożenia RAW.
- Aktualny stan zachowania sześciu ról, BOSS i RAW opisuje wcześniejszy [raport statycznej weryfikacji RBAC](VELTRA_STAGE_7A0_RBAC_VERIFICATION_REPORT.md); nie jest to dowód zmian ani aktualnej akceptacji runtime.

## 4. Bezpieczeństwo środowiska i następne kroki

Nie zmieniono kodu aplikacji, istniejących raportów, bazy, kont, grantów, konfiguracji aktywacji modułów, sekretów, kontenerów, wolumenów ani historii Git. Nie przełączano gałęzi, nie uruchamiano i nie restartowano usług, nie wdrażano ani nie rozpoczynano Stage 7A.1.

Po decyzji o baseline należy osobno wykonać implementację i skontrolować istniejące konflikty danych bez automatycznej korekty; przed testami zapisującymi potwierdzić izolowane połączenie z bazą. Następnie wykonać wymagane testy backendu, sprawdzenie typów i build frontendu. Dopiero po ich powodzeniu, odrębnej autoryzacji i sprawdzeniu planu migracji można planować lokalne wdrożenie i akceptację w przeglądarce. Żadne z tych działań nie zostało wykonane w tym etapie.
