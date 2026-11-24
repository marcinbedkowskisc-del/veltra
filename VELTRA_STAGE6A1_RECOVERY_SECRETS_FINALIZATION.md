# Stage 6A.1 — konfiguracja odzyskiwania

- **Wynik: PASS** — lokalna konfiguracja odzyskiwania została zabezpieczona bez zmiany istniejących wartości.
- **Lokalizacja:** `/home/veltra/backups/stage6a1/recovery-config/`. Procedura odtworzenia i metadane ról znajdują się w `RECOVERY.md` w tym katalogu.
- **Uprawnienia:** katalog `0700`, pliki `0600`, właściciel `veltra`; brak rozszerzonych ACL. Dostęp wymaga konta właściciela albo uprawnień administratora systemu. Katalog znajduje się poza repozytorium Git.
- **Weryfikacja odczytu:** konfigurację odczytano niezależnie od katalogu sesji. Renderowana konfiguracja Compose jest zgodna z dotychczasową; poświadczenia trzech ról bazy i klucz podpisu zweryfikowano bez ujawniania wartości. Działającej konfiguracji nie zmieniono.
- **Pozostała blokada:** raport nie został przesłany na Google Drive — dostępna przeglądarka wymaga zalogowania. Lokalna kopia odzyskiwania znajduje się na tym samym hoście; nie jest kopią poza nim.
