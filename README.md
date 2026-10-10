# SymconAppleTV

IP-Symcon-Modul für das **Apple TV**. Es steuert das Gerät lokal über das
Companion-Protokoll, also den Weg der Fernbedienung im iPhone. Es braucht
keine Cloud, kein MQTT und kein Zusatzgerät, sondern nur PHP auf der
Symcon-Box.

> 🙏 **Thanks to [Pierre Ståhl](https://github.com/postlund) and
> [pyatv](https://github.com/postlund/pyatv).** Pierre has been building
> pyatv since 2017. The Companion protocol layer of this module is a PHP
> port of his work. Without pyatv, this module would not exist.

Das Modul kann:

- das Apple TV aufwecken und in den Ruhezustand schicken
- den Zustand abfragen (Ruhezustand, Bildschirmschoner, wach)
- Apps starten, als Auswahl oder als eigener Schalter je App, z. B. für die
  Visualisierung, die HomeKit-Bridge und Apple Kurzbefehle; die Schalter legt
  das Modul selbst an und hält sie aktuell
- Tasten der Siri Remote und Wiedergabebefehle senden

## Voraussetzungen

- IP-Symcon ab 8.0, getestet mit 9.0 auf einer SymBox
- PHP-Erweiterungen **sodium**, **gmp** und **sockets** (bei Symcon dabei)
- Apple TV HD oder 4K, getestet mit einem Apple TV 4K

Für die Suche fragt das Modul per mDNS (Bonjour). Steht Symcon in einem
anderen Netz als das Apple TV, muss der Router mDNS weiterreichen (bei
UniFi: „Multicast DNS“ für beide Netze). Ohne Suche lässt sich die Adresse
auch von Hand eintragen.

## Einrichtung

1. In der **Modulsteuerung** (Kern-Instanzen → Modules) diese URL hinzufügen:
   `https://github.com/HeikoSchroers/SymconAppleTV`
2. Eine Instanz **Apple TV Configurator** anlegen. Sie listet die Apple TVs
   im Netz. Mit **Erstellen** entsteht die Geräte-Instanz mit Adresse, Port
   und Gerätenamen.
3. In der Geräte-Instanz unter **Kopplung** auf **Koppeln** klicken. Der
   Fernseher zeigt eine vierstellige PIN.
4. Die PIN eintragen und **PIN senden** klicken (2 Minuten Zeit). Die Zeile
   darüber meldet „Gekoppelt am …, n Apps“ oder den Fehler.
5. Unter **App-Schalter** ist ab Werk **Schalter für jede installierte App**
   an: Jede App bekommt einen Schalter. Wer nur einzelne will, schaltet die
   Option aus, kreuzt die Apps in der Liste an und klickt **Änderungen
   übernehmen**.

**Adresse:** Antwortet die eingetragene Adresse nicht, etwa nach einem
DHCP-Wechsel, sucht die Instanz das Apple TV über den Gerätenamen per mDNS.
Die neue Adresse merkt sie sich bis zum nächsten Übernehmen und zeigt sie im
Formular an. Eine feste Adresse im Router ist trotzdem besser.

## Kopplung

Der Kopplungsschlüssel steht als Attribut in der Instanz, nicht in einer
Variable. Er erscheint deshalb weder in der Visualisierung noch im Archiv.
In Sicherungen der Symcon-Einstellungen steht er allerdings mit drin. Wer ihn
hat, kann das Apple TV steuern.

Auf dem Apple TV erscheint die Kopplung unter *Einstellungen › Fernbedienungen
und Geräte* mit dem Namen aus **Name dieser Fernbedienung** (Vorgabe
„Symcon“). Dort lässt sie sich entfernen. **Kopplung löschen** in der
Instanz entfernt nur die Seite in Symcon.

Nach einem Gerätetausch oder Zurücksetzen lehnt das Apple TV die Anmeldung
ab. Die Instanz meldet dann „Kopplung abgelehnt – neu koppeln“.

## Variablen

| Variable | Typ | Bedeutung |
|---|---|---|
| Eingeschaltet | Schalter | Ein weckt das Apple TV, Aus schickt es in den Ruhezustand |
| Zustand | Auswahl | unbekannt, Ruhezustand, Bildschirmschoner, wach, untätig |
| App | Auswahl | installierte Apps; die Auswahl startet die App |
| je App (bzw. je angekreuzter App) | Schalter | Ein startet die App, Aus schickt das Apple TV in den Ruhezustand |

Die Auswahl der Variable **App** und die App-Schalter baut das Modul aus der
App-Liste des Geräts. Die Liste holt es beim Koppeln, bei **App-Liste neu
laden** im Formular, bei `ATV_GetApps($id)` und von selbst alle 6 Stunden
(**App-Liste neu laden alle**). Das geschieht aber nur, wenn das Apple TV
gerade wach ist oder den Bildschirmschoner zeigt. Ein schlafendes Gerät wird
dafür nicht geweckt, sonst schaltete es über HDMI-CEC nachts den Fernseher
ein.

- **Neue App:** Sie bekommt einen eigenen Schalter.
- **Gelöschte App:** Ihr Schalter wird versteckt, nicht gelöscht. Links und
  Einträge in der HomeKit-Bridge zeigen so nicht ins Leere. Kommt die App
  wieder, erscheint der Schalter wieder. Einen Schalter, den jemand von Hand
  versteckt hat, zeigt das Modul nicht wieder an.
- **Abgewählt:** Gelöscht wird ein Schalter nur, wenn man ihn im Formular
  abwählt, solange die App installiert ist.

Die Kennung der Schalter-Variable (`APP_` und eine Prüfsumme der App-Kennung)
hängt nur an der App. Sie bleibt also auch beim Umbenennen gleich.

Die App-Schalter schalten sich gegenseitig aus: Startet eine App, geht ihr
Schalter an und alle anderen aus. Damit taugen sie für die HomeKit-Bridge,
und Apple Kurzbefehle müssen nur einen Schalter setzen.

Das Apple TV meldet die laufende App nicht von sich aus. **App** und die
App-Schalter zeigen deshalb die zuletzt aus Symcon gestartete App. Geht das
Apple TV in den Ruhezustand, setzt die Abfrage beides zurück.

## Funktionen

| Funktion | Bedeutung |
|---|---|
| `ATV_PowerOn($id)` | aufwecken; ein laufender Bildschirmschoner wird beendet |
| `ATV_PowerOff($id)` | Ruhezustand |
| `ATV_GetState($id)` | 1 Ruhezustand, 2 Bildschirmschoner, 3 wach, 4 untätig, 0 unbekannt |
| `ATV_LaunchApp($id, 'com.netflix.Netflix')` | App starten (weckt vorher); eine URL öffnet einen Link |
| `ATV_GetApps($id)` | installierte Apps als Kennung => Name; legt Schalter für neue Apps an |
| `ATV_GetKnownApps($id)` | zuletzt geholte App-Liste, ohne Verbindung zum Apple TV |
| `ATV_SendKey($id, $Taste)` | Taste drücken: 1–4 Hoch/Runter/Links/Rechts, 5 Menü, 6 OK, 7 TV, 8/9 Lauter/Leiser, 14 Wiedergabe/Pause |
| `ATV_MediaCommand($id, $Befehl)` | 1 Play, 2 Pause, 3 nächster, 4 voriger Titel |
| `ATV_Update($id)` | Zustand abfragen und Variablen setzen |
| `ATV_IsPaired($id)` | Kopplung vorhanden? |

Die Funktionen liefern `true` bzw. den Wert bei Erfolg. Bei einem Fehler
melden sie eine Warnung und liefern `false` bzw. 0. Jeder Aufruf baut eine
eigene Verbindung auf und dauert etwa eine Sekunde.

`ATV_Search($idKonfigurator)` liefert die gefundenen Apple TVs, auch ohne
Instanz.

## Eigenheiten

- Lauter/Leiser wirkt nur, wenn das Apple TV die Lautstärke steuert (HDMI-CEC
  oder IR-Lernfunktion).
- Der Ruhezustand des Apple TV schaltet über HDMI-CEC meist auch den
  Fernseher aus, das Aufwecken ihn ein. Das hängt am Fernseher.
- Das reine Aufwecken lässt einen laufenden Bildschirmschoner stehen.
  `ATV_PowerOn` drückt deshalb in diesem Fall zusätzlich Menü, was ihn
  beendet. Ein App-Start beendet ihn von selbst.
- Abgefragt wird ab Werk jede Minute. Ein schlafendes Apple TV beantwortet
  die Abfrage, ohne aufzuwachen.

## Herkunft

Das Companion-Protokoll ist aus [pyatv](https://github.com/postlund/pyatv)
(MIT-Lizenz, Copyright (c) 2020 Pierre Ståhl) nach PHP portiert, nur der
benötigte Teil: OPACK, TLV8, HAP-Kopplung (SRP-6a, 3072 Bit, SHA-512),
Pair-Verify (X25519, Ed25519), die verschlüsselten Rahmen
(ChaCha20-Poly1305) und die Suche per mDNS. SRP, OPACK und TLV8 sind mit
festen Testwerten gegen die Originalbibliotheken (srptools, pyatv) geprüft.

## Lizenz

MIT, siehe [LICENSE](LICENSE). Der Lizenztext von pyatv steht in [LICENSE-pyatv](LICENSE-pyatv).
