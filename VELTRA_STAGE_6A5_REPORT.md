# Stage 6A.5 — PARTIAL

## Wynik i zakres

Zaimplementowano trzy zakładki CREATE/EDIT, zachowanie danych, obsługę
deklaracji alergenów i wskazywanie pierwszego błędnego pola. Widok edycji
zachowuje poprzednie wersjonowanie oraz `If-Match`. Otwarcie nowego szkicu
przechodzi do widoku roboczego. Wcześniejsze formatowanie ilości i ukrycie
UUID zachowano.

Dodano `POST /api/v1/raw-materials/create-approved`: atomowy CREATE, SUBMIT
i APPROVE, z deklaracją, audytem oraz idempotentnym ponowieniem rezerwacji SKU.
Serwer wymaga administratora i efektywnych grantów CREATE, ALLERGEN_MANAGE,
SUBMIT oraz APPROVE, ponownie sprawdza kontekst i gotowość. Nie zmieniono
grantów ani globalnego RBAC. Pozostali użytkownicy nadal zapisują szkic.
EDIT nadal wymaga osobnego SUBMIT i APPROVE; analogicznego jednego kliknięcia
dla edycji nie zaimplementowano.

Zmiany obejmują `README.md`, serwis, kontrolery i requesty RAW, trasy i testy
backendu oraz formularze, API, katalog, preview i testy frontendu. Szczegóły
kontraktu opisano w README. Zachowano wcześniejsze niezacommitowane prace.

## Weryfikacja

- Backend: `docker compose -p veltra-stage6a1 --profile testing` z plikiem
  środowiska i dwoma lokalnymi override'ami Stage 6A.1, `run --rm --no-deps`,
  katalogami bieżącego `backend/app`, `backend/tests` i `backend/routes`
  zamontowanymi tylko do odczytu, `backend-test php artisan test --filter=RawMaterial`:
  **237 PASS, 2600 asercji**, izolowana baza `veltra_testing`. Po dodaniu
  asercji capability powtórzono wybrane testy: **7 PASS, 111 asercji**.
- Pierwszy szeroki przebieg wykrył regresję walidacji historycznych
  deklaracji i brak skanera ClamAV. Regresję poprawiono; testowy ClamAV
  uruchomiono dla ponownego przebiegu, następnie przywrócono do stanu
  zatrzymanego.
- `npm --prefix frontend test`: **166 PASS**, 13 plików.
- `npm --prefix frontend run build`: PASS, w tym TypeScript.
- `npm --prefix frontend run lint`: exit 0, istniejący warning Fast Refresh
  w `frontend/src/visualPreview.tsx:37`.
- Pint: sześć kluczowych plików backendu PASS; szerszy test dziewięciu plików
  FAIL z powodu istniejącej kolejności importów w `backend/routes/api.php`.
- `git diff --check`: PASS przed aktualizacją niniejszego raportu.

Polecenie szerokiej regresji backendu:

```bash
docker compose -p veltra-stage6a1 --profile testing \
  --env-file /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/veltra-stage6a1.env \
  -f compose.yaml \
  -f /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/compose.stage6a1-images.yaml \
  -f /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/compose.stage6a1-test-services.yaml \
  run --rm --no-deps \
  -v /home/veltra/projekty/veltra/backend/app:/var/www/html/app:ro \
  -v /home/veltra/projekty/veltra/backend/tests:/var/www/html/tests:ro \
  -v /home/veltra/projekty/veltra/backend/routes:/var/www/html/routes:ro \
  backend-test php artisan test --filter=RawMaterial
```

W normalnej aplikacji, w zalogowanej sesji opiekuna, utworzono testowy
`RAW-000004`, a następnie zapisano drugą rewizję z deklaracją alergenów.
Odczyty HTTP workbench/editor/revisions: 200/200/200; widoczna rewizja 2,
status DRAFT, deklaracja DECLARED, dwie rewizje. Podczas testu zachowano
opis, ilości i SKU. Konto nie ma administracyjnej akcji łącznej, dlatego
rzeczywisty CREATE+APPROVE administratora nie został wykonany. Sam łączny
przepływ i role/granty sprawdzono w testach backendu na PostgreSQL.

Sprawdzono rzeczywisty CREATE desktopowo, nawigację strzałkami, oznaczenie
błędu i fokus kategorii; EDIT mobilnie 390×844 bez poziomego overflow,
z widocznymi przyciskami zapisu. Zrzuty wyświetlono w sesji; plików PNG
nie zapisano. Nie sprawdzono w przeglądarce wszystkich stanów błędów
i administracyjnej ścieżki jednym kliknięciem.

## Stan i przekazanie

Aplikacja `http://localhost:5174/` i backend `http://localhost:8001/`
pozostają uruchomione. Backend bez sesji zwraca 401. Globalnie
`RAW_MATERIALS = NOT_READY`; lokalny opt-in pozostaje ograniczony do
izolowanej instancji. Kod skopiowano do istniejących kontenerów; odtworzenie
ich ze starszych obrazów utraci te kopie. ClamAV po testach zatrzymany;
podstawowe lokalne kontenery pozostają uruchomione.

Zmiany pozostają niezacommitowane, w tym wcześniejszy raport naprawy
logowania. Bez pushu, deploymentu, resetu bazy i zapisu na Google Drive.
Do pełnego odbioru potrzeba rzeczywistego testu administratora z kompletem
istniejących grantów oraz decyzji o realizacji łącznego zatwierdzania EDIT.
