# Stage 6A.1 — wynik

**Status: PARTIAL.** Lokalna aplikacja działa na `http://localhost:5174/`; frontend i API zwracają HTTP 200. Izolowany PostgreSQL Stage 6A.1 jest zdrowy, a backend raportuje połączenie z bazą. Usługi aplikacji pozostawiono uruchomione; tymczasowy kontener ClamAV użyty wyłącznie w testach zatrzymano.

Istniejący ekran logowania i mechanizm sesji działają; sprawdzono CSRF i odmowę dla błędnego hasła oraz żądań bez sesji. Konto deweloperskie zostało utworzone przez istniejący bootstrap, lecz wymaga aktywacji i ustawienia hasła przez właściciela w Mailpit (`http://localhost:8026/`). Z tego powodu udane logowanie użytkownika nie zostało jeszcze potwierdzone.

`RAW_MATERIALS` nadal ma stan `NOT_READY`. Kod guarda zwraca 503 dla endpointów `/api/v1/raw-materials/*`, gdy moduł jest niedostępny, więc blokuje zarówno odczyty katalogu/szczegółów/historii, jak i SKU oraz zapisy; endpoint capabilities jest osobny. Nie zmieniano guarda ani nie wykonywano zapisów RAW na bazie runtime. Nie testowano odpowiedzi 503 w uwierzytelnionej sesji. Testy z fixture READY w izolowanym runnerze nie oznaczają aktywacji modułu.

Wyniki: backend **390 passed, 3843 assertions**, 509,70 s; frontend **141/141**; build/typecheck, lint i `git diff --check` zakończone zgodnie z poprzednim podsumowaniem. Lint zgłosił wcześniejsze ostrzeżenie Fast Refresh, a pełny Pint ma istniejący problem `ordered_imports` w `backend/routes/api.php`; nie zmieniano niezwiązanych plików.

Zmiany tej fazy: [scripts/init-env.php](./scripts/init-env.php), [README.md](./README.md). Zachowano checkpoint `b432ddeebfd2fcf081099f689f57eaf6606adbf1` i istniejące zmiany Stage 5B; bez commitu, pushu, deploymentu i aktywacji READY. Następny krok: właściciel aktywuje konto, loguje się pod adresem aplikacji i osobno zatwierdza jawny, izolowany wariant testów integracyjnych RAW przy `NOT_READY`.
