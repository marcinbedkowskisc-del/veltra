# VELTRA 2.0 — PA-06 implementation and verification

## Zakres

PA-06 dodaje administracyjny katalog dostawców oparty o istniejące `organization_suppliers` i powiązania surowców RAW. Nie powstał drugi katalog dostawców ani nowe uprawnienie. Interfejs administracyjny obsługuje dane firmy i osoby kontaktowe, wyszukiwanie podobnych nazw, archiwizację i przywracanie. Kody dostawców pozostają ukryte w odpowiedziach administracyjnych.

Dane identyfikacji podatkowej są szyfrowane, a znormalizowany odcisk służy wyłącznie do wykrywania duplikatów. Zmiany używają istniejących mechanizmów autoryzacji mutacji, idempotencji i audytu. Migracja tworzy tabelę kontaktów i rozszerza istniejącą kartotekę.

Widoki katalogu RAW, szczegółów i edytora pokazują nazwy dostawców powiązanych z surowcem. Nazwy są dostępne bez uprawnienia do odczytu kodów dostawców i bez uprawnień finansowych; nie ujawniają kodów dostawców.

## Naprawa regresji RAW

Pierwsze pełne uruchomienie backendu zakończyło się wynikiem 464 zaliczonych i 14 niezaliczonych testów (4503 asercje). Niepowodzenia RAW i integracji wskazały ten sam błąd w `RawMaterialService::buildCatalogQuery`: zapytanie `$query` było używane wewnątrz zamknięcia `leftJoin`, które nie przechwytywało tej zmiennej. Umieszczenie agregacji nazw dostawców wewnątrz złączenia rewizji powodowało `Undefined variable $query` przy katalogu, szczegółach i przepływach roboczych.

Złączenie agregujące nazwy dostawców zostało przeniesione na zewnętrzne zapytanie, poza zamknięcie złączenia rewizji. Nie zmieniono logiki dostępu ani projekcji RAW poza dodaniem nazw dostawców.

Naprawiono także syntetyczny podgląd: odpowiedź wyszukiwania podobnych nazw ma teraz kształt zgodny z kontraktem API (`data`).

## Podgląd przeglądarkowy

Otwórz lokalny podgląd: <http://localhost:5175/supplier-preview.html>. To rzeczywiste komponenty React z syntetycznym dostawcą BANASIK i syntetycznymi odpowiedziami API; nie łączy się ze Stage ani produkcją.

Sprawdzone w przeglądarce: katalog i szczegóły dostawcy, otwieranie formularzy edycji i dodawania, lista kontaktów oraz formularz dodawania kontaktu. Wszystkie formularze wyświetliły się bez błędów API. Przykładowe dane pozostają wyraźnie oznaczone jako syntetyczne.

Zapisane zrzuty znajdują się w [`review/pa06-supplier-directory/`](./review/pa06-supplier-directory/):

- [`desktop-1920x1080.png`](./review/pa06-supplier-directory/desktop-1920x1080.png) — PNG 1920 × 1080 px.
- [`desktop-1440x900.png`](./review/pa06-supplier-directory/desktop-1440x900.png) — PNG 1440 × 900 px.
- [`mobile-390x844.png`](./review/pa06-supplier-directory/mobile-390x844.png) — obraz ma 390 × 844 px.
- [`supplier-details.png`](./review/pa06-supplier-directory/supplier-details.png), [`supplier-edit-form.png`](./review/pa06-supplier-directory/supplier-edit-form.png), [`supplier-add-form.png`](./review/pa06-supplier-directory/supplier-add-form.png) — zrzuty widoku 1440 × 900 px.
- [`supplier-contact-form.png`](./review/pa06-supplier-directory/supplier-contact-form.png) — zrzut samego formularza (697 × 305 px).

Ograniczenie narzędzia przeglądarkowego: rozmiary PNG i emulowane `innerWidth`/`innerHeight` odpowiadają żądanym rozmiarom, ale renderer namalował aplikację tylko na części powierzchni obrazu (około 1149 × 646 px dla widoków desktopowych i około 294 × 669 px w obrazie mobilnym); pozostała część jest pusta. Zrzuty pokazują rzeczywiste komponenty, lecz nie potwierdzają wizualnego wypełnienia ani układu całej powierzchni w tych rozdzielczościach. Wymagana jest ponowna akceptacja wizualna w przeglądarce bez tego ograniczenia.

## Weryfikacja

- Migracje wykonano w odizolowanym środowisku testowym; baza nie łączyła się ze Stage ani produkcją.
- Testy RAW, lokalnej integracji i katalogu dostawców: **172 zaliczone, 2038 asercji**.
- Pełny frontend: **233 zaliczone, 22 pliki testowe**.
- TypeScript i build frontendu: zaliczone (`tsc -b`, Vite).
- Lint frontendu: zaliczony; pozostaje istniejące ostrzeżenie `react(only-export-components)` w `src/visualPreview.tsx`.
- PHP Pint dla zmienionego `RawMaterialService.php`: zaliczony.
- Pełny backend z działającym ClamAV w odizolowanym środowisku: **478 zaliczonych, 4724 asercje**.

## Ograniczenia i gotowość

Nie zmieniono RAW-owych formularzy deklaracji, historii ani powiązań dostawców — wyświetlane są wyłącznie nazwy z już istniejących powiązań. Nie wykonano push ani deploymentu i nie rozpoczęto PA-07.

Funkcjonalność i regresje są zweryfikowane, a podgląd jest gotowy do oceny właściciela. Końcowa akceptacja wizualna wymaga jednak ponownego sprawdzenia pełnego obszaru desktopowego i mobilnego w przeglądarce bez ograniczenia renderera. Nie wykonano push ani deploymentu i nie rozpoczęto PA-07.

## Lokalny commit

- Implementacja PA-06 i weryfikowane poprawki RAW: `2c5617bcca7aefc551041d2f81ec1f2f63af93a7` (`feat(admin): add shared supplier directory`).
- Raport zapisano osobnym lokalnym commitem; nie wykonano push ani deploymentu.
