# VELTRA 2.0 — RAW pre-staging preflight

**Data:** 2026-11-23

**Branch:** `main`

**HEAD przed zmianami:** `3006659240b3a6bdba5b6c0b7b2583b99d21fbe6` (`061`)

**Zakres:** kontrola historii, Pint, Composer audit i odczytowy preflight istniejącego lokalnego stosu Stage 6A.1.
**Decyzja:** bez aktywacji, wdrożenia, zmian readiness, baz, uprawnień, commitów i pushy.

## Podsumowanie

- HEAD zawiera wymagany commit `0a7366effd43159539b0c9c49eab873ccdf2e40f`. Jest on bezpośrednim rodzicem bieżącego HEAD.
- Usunięto wyłącznie istniejący problem formatowania Pint w `backend/app/Domain/RawMaterials/RawMaterialService.php`. Pint przechodzi teraz dla wszystkich **409 plików**.
- `composer audit --locked --no-interaction` zakończył się wynikiem **No security vulnerability advisories found**.
- Zidentyfikowano istniejący lokalny stos `veltra-stage6a1`, ale nie zdalne/udostępnione środowisko STAGING. Frontend odpowiada HTTP 200, natomiast API zwraca HTTP 503; kontener PostgreSQL jest zatrzymany.
- Runtime’owych readiness, stanu włączenia dla lokalizacji ani aktualnej konfiguracji administratora nie dało się potwierdzić, ponieważ baza jest niedostępna.
- **Sama zgoda właściciela i kontrolowana aktywacja nie wystarczą obecnie do rozpoczęcia testów ręcznych.** Najpierw należy przywrócić dostępność istniejącego izolowanego środowiska i ponownie zweryfikować stan modułu oraz dostęp dla testerów. Historyczny raport Stage 6A.6-FIX wskazuje również na niepełny zestaw grantów RAW i brak zgodnej ścieżki ich nadania chronionej tożsamości; nie traktować tego jako aktualnego odczytu bazy.

## 1. HEAD i odniesienie `32e3bff`

Weryfikacja historii:

```text
HEAD:   3006659240b3a6bdba5b6c0b7b2583b99d21fbe6 (061)
parent: 0a7366effd43159539b0c9c49eab873ccdf2e40f
older:  32e3bffc977a25b42f1e9f7876155e4442005320
```

`0a7366e` jest przodkiem HEAD (bezpośrednim rodzicem) i obejmuje dwa testy regresyjne wskazane w [raporcie końcowej weryfikacji RAW](./VELTRA_RAW_FINAL_VERIFICATION_REPORT.md). Wzmianka `32e3bff` w [ocenie gotowości](./VELTRA_RAW_READINESS_FINAL_ASSESSMENT.md) jest prawidłowym, starszym punktem bazowym raportu, utworzonym przed commitami `0a7366e` i `061`; nie jest bieżącym HEAD ani brakującym commitem.

## 2. Pint — tylko formatowanie

Pint poprawił `method_chaining_indentation` w `RawMaterialService.php` (łańcuch metod w bloku propozycji dostawców) oraz odstęp wymagany przez ten sam formatter. Zmiana jest wyłącznie formatowaniem; nie zmienia logiki aplikacji.

Wynik ponownego sprawdzenia całego backendu:

```text
Laravel Pint --test: PASS — 409 files
```

## 3. Composer security audit

Ponowiono:

```bash
composer audit --locked --no-interaction
```

Wynik: **No security vulnerability advisories found.** Rejestr był osiągalny. Composer zgłosił, że katalog cache nie jest zapisywalny, ale kontynuował kontrolę bez cache i zakończył ją pomyślnie. Nie zmieniano zależności ani plików lock.

## 4. Odczytowy preflight lokalnego Stage 6A.1

### Identyfikacja i dostępność

Środowisko zidentyfikowano na podstawie [compose.stage6a1.yaml](./compose.stage6a1.yaml), dokładnych etykiet działających kontenerów `veltra-stage6a1` oraz wcześniejszych raportów tego lokalnego stosu. Jest to **lokalny, izolowany Stage 6A.1**, a nie potwierdzone zdalne STAGING.

Odczyt statusu kontenerów i bezstanowe żądania HTTP:

| Element | Wynik |
|---|---|
| Frontend `localhost:5174` | HTTP 200 |
| Backend `localhost:8001/api/health` | HTTP 503; `postgresql: down`, `redis: up` |
| Kontener backendu | działa, status `unhealthy` |
| PostgreSQL | `exited`, kod wyjścia 127 |
| Redis | działa, `healthy` |
| Mailpit | działa, `healthy` |

Nie uruchamiano ani nie restartowano usług; nie próbowano naprawiać lub montować istniejącego wolumenu bazy.

### Readiness i lokalizacja

- Kodowy katalog produkcyjny w `backend/app/Domain/Modules/DefinedModuleCatalog.php` tworzy `RAW_MATERIALS` domyślnie jako `ready=false`; reguła dostępności wymaga readiness oraz jawnego włączenia.
- Ostatni dostępny raport runtime Stage 6A.6-FIX podawał `readiness = NOT_READY` i lokalną dostępność `true`. To historyczny zapis, nie potwierdzenie obecnego stanu.
- Aktualnej wartości `location_module_states` ani efektywnej dostępności dla konkretnej lokalizacji **nie zweryfikowano**: PostgreSQL był zatrzymany. Nie odczytywano żadnej bazy ani nie omijano gate’ów.

### Konfiguracja administratora i testera

- Nie odczytywano haseł, tokenów, cookies, plików poświadczeń ani wartości sekretów.
- Nie wykonywano zapytań do bazy ani sprawdzenia kont/grantów na żywo, ponieważ baza była niedostępna.
- Historyczny raport Stage 6A.6-FIX dokumentuje ograniczony zestaw grantów RAW dla konta opiekuna oraz brak zgodnej drogi do nadania pozostałych wymaganych grantów chronionej tożsamości. Wcześniejszy raport Stage 6A.6 wskazuje też, że sam status administratora nie zastępuje wymaganych grantów. Są to przesłanki do ponownej weryfikacji po przywróceniu bazy, nie bieżący stan kont.
- Aktualny dostęp administracyjny/testowy i jego zakres pozostają **NOT VERIFIED**. Nie zmieniano ról ani uprawnień.

## 5. Czy coś poza zgodą i aktywacją blokuje testy ręczne?

**Tak, w obecnym stanie są dodatkowe blokery:**

1. **Niedostępny backend:** istniejący lokalny Stage 6A.1 nie jest gotowy do testów, gdy PostgreSQL jest zatrzymany, a API zwraca 503.
2. **Brak bieżącej weryfikacji runtime:** nie można potwierdzić gotowości RAW ani włączenia dla lokalizacji, dopóki baza nie będzie dostępna.
3. **Dostęp testera:** aktualne konto/granty są niezweryfikowane; ostatnie raporty odnotowują niepełny zestaw grantów RAW i problem zgodnego delegowania uprawnień do chronionej tożsamości. Może to blokować część lub całość planowanego zakresu testów.

Zatem akceptacja właściciela i późniejsza kontrolowana aktywacja są konieczne, ale same nie rozwiązują dostępności środowiska ani dostępu testera. Nie należy obchodzić gate’ów ani samodzielnie rozszerzać uprawnień.

## 6. Dokładne następne kroki

1. Właściciel określa i zatwierdza zakres ręcznych testów RAW oraz osobno akceptuje gotowość i kontrolowaną aktywację. Do tego czasu pozostawić readiness i stan lokalizacji bez zmian.
2. Administrator środowiska diagnozuje kontener PostgreSQL lokalnego Stage 6A.1 i przywraca go zgodnie z osobno zatwierdzoną procedurą, zachowując istniejący wolumen. Nie resetować bazy ani nie odtwarzać jej z innego środowiska.
3. Po przywróceniu bazy ponowić bezstanowe sprawdzenie health i wykonać odczytową weryfikację: `RAW_MATERIALS` readiness, `location_module_states` dla wybranej lokalizacji oraz konfiguracji konta i grantów wymaganych przez zatwierdzony zakres testów. Raportować tylko zanonimizowane stany, bez poświadczeń.
4. Jeśli brakuje uprawnień, uzyskać zatwierdzoną, audytowaną ścieżkę nadania ich zgodnie z RBAC. Nie stosować samonadania, zmian bezpośrednio w bazie ani obejścia ochrony tożsamości.
5. Dopiero po tych kontrolach wykonać osobny, zatwierdzony proces zmiany readiness/włączenia lokalizacji i przeprowadzić testy zgodnie z zakresem. Niniejsze zadanie nie upoważnia do żadnej z tych zmian.

## Stan zmian i ograniczenia

- Zmieniono tylko formatowanie w `backend/app/Domain/RawMaterials/RawMaterialService.php` i dodano niniejszy raport.
- Nie wykonano migracji, zapisów do baz, zmian readiness, lokalizacji, ról ani grantów, aktywacji RAW/BOSS, wdrożenia, commitu ani pushu.
- Istniejący nieśledzony `VELTRA_RAW_READINESS_FINAL_ASSESSMENT.md` pozostawiono bez zmian.
