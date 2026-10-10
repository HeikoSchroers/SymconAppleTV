<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Companion.php';

use SymconAppleTV\Connection;
use SymconAppleTV\Discovery;
use SymconAppleTV\NotConfirmedException;

/**
 * Apple TV ueber das Companion-Protokoll, lokal ohne Cloud und ohne
 * Zusatzgeraet: aufwecken, Ruhezustand, Zustand, Apps starten, Tasten.
 *
 * Jeder Befehl baut eine eigene Verbindung auf und meldet sich mit der
 * gespeicherten Kopplung an (etwa 1 s). Die Kopplung steht im Attribut
 * "Pairing" der Instanz, nicht in einer Variable: Wer sie hat, kann das
 * Apple TV steuern.
 *
 * Die Kopplung braucht zwei Schritte auf derselben Verbindung (Anfrage,
 * dann PIN vom Bildschirm). Sie laeuft deshalb in einem eigenen Thread
 * (RunPairing), der bis zu 2 Minuten auf die PIN aus dem Formular wartet.
 */
class AppleTVDevice extends IPSModule
{
    // Darstellungen von IP-Symcon (/usr/share/symcon/presentations/)
    private const P_AUFZ = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';
    private const P_SCHALTER = '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}';

    // HID-Tasten (pyatv HidCommand)
    private const HID_SLEEP = 12;
    private const HID_WAKE = 13;
    private const HID_MENU = 5;

    // FetchAttentionState (pyatv SystemStatus)
    private const ZUSTAND = [0 => 'unbekannt', 1 => 'Ruhezustand', 2 => 'Bildschirmschoner', 3 => 'wach', 4 => 'untätig'];

    // Status der Instanz (form.json)
    private const S_NICHT_GEKOPPELT = 201;
    private const S_NICHT_ERREICHBAR = 202;
    private const S_ABGELEHNT = 203;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 49153);
        $this->RegisterPropertyString('DeviceName', '');
        $this->RegisterPropertyString('ClientName', 'Symcon');
        $this->RegisterPropertyInteger('Interval', 60);
        $this->RegisterPropertyString('AppSwitches', '[]');

        $this->RegisterAttributeString('Pairing', '');
        $this->RegisterAttributeString('Apps', '{}');
        $this->RegisterAttributeString('FoundAddress', '');
        $this->RegisterAttributeString('PairingInfo', '');

        $this->RegisterTimer('Update', 0, 'ATV_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Adresse geaendert oder Neustart: erst wieder die eingetragene versuchen
        $this->WriteAttributeString('FoundAddress', '');

        $this->registerVariables();

        $intervall = $this->ReadPropertyInteger('Interval');
        $this->SetTimerInterval('Update', $intervall > 0 ? max(10, $intervall) * 1000 : 0);

        if (!$this->IsPaired()) {
            $this->SetStatus(self::S_NICHT_GEKOPPELT);
        } elseif ($this->ReadPropertyString('Host') === '' && $this->ReadPropertyString('DeviceName') === '') {
            $this->SetStatus(IS_INACTIVE);
        } else {
            $this->SetStatus(IS_ACTIVE);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'POWER') {
            $Value ? $this->PowerOn() : $this->PowerOff();
            return;
        }
        if ($Ident === 'APP') {
            if ((string) $Value !== '') {
                $this->LaunchApp((string) $Value);
            }
            return;
        }
        if (str_starts_with($Ident, 'APP_')) {
            if (!$Value) {
                $this->PowerOff();
                return;
            }
            foreach ($this->apps() as $bundle => $name) {
                if (self::schalterIdent($bundle) === $Ident) {
                    $this->LaunchApp($bundle);
                    return;
                }
            }
            throw new Exception('App zu ' . $Ident . ' nicht in der App-Liste');
        }
        throw new Exception('Unbekannter Ident ' . $Ident);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $info = $this->ReadAttributeString('PairingInfo');
        if ($info === '') {
            $info = $this->IsPaired() ? 'Gekoppelt.' : 'Nicht gekoppelt.';
        }
        $gefunden = $this->ReadAttributeString('FoundAddress');
        $form['actions'] = $this->formularFuellen($form['actions'], [
            'PairingInfo'  => ['caption' => $info],
            'FoundAddress' => ['caption' => 'Zuletzt gefunden unter ' . $gefunden . ' (abweichend von der eingetragenen Adresse)', 'visible' => $gefunden !== ''],
        ]);
        $form['elements'] = $this->formularFuellen($form['elements'], [
            'AppSwitches' => ['values' => $this->schalterListe()],
        ]);
        return json_encode($form);
    }

    // ===================== Oeffentliche Funktionen =====================

    /** Zustand abfragen und die Variablen setzen. */
    public function Update(): bool
    {
        // Ohne Warnung: Der Zeitgeber soll ein ausgeschaltetes Netz nicht ins Log schreiben
        return $this->zustandHolen(false) > 0;
    }

    /**
     * 1 Ruhezustand, 2 Bildschirmschoner, 3 wach, 4 untaetig, 0 unbekannt
     * (nicht gekoppelt oder nicht erreichbar).
     */
    public function GetState(): int
    {
        return $this->zustandHolen(true);
    }

    private function zustandHolen(bool $warnen): int
    {
        $zustand = $this->ausfuehren(fn (Connection $c) => (int) ($c->command('FetchAttentionState')['state'] ?? 0), $warnen) ?? 0;
        $this->SetValue('STATUS', $zustand);
        if ($zustand == 1) {
            $this->SetValue('POWER', false);
            $this->SetValue('APP', '');
            $this->schalterSetzen('');
        } elseif ($zustand > 1) {
            $this->SetValue('POWER', true);
        }
        return $zustand;
    }

    /**
     * Aufwecken. Ein laufender Bildschirmschoner endet dabei: Wake allein
     * laesst ihn stehen, Menue beendet ihn (am Geraet geprueft 10.10.2026).
     */
    public function PowerOn(): bool
    {
        $ok = $this->ausfuehren(function (Connection $c) {
            $c->command('_hidC', ['_hBtS' => 2, '_hidC' => self::HID_WAKE]);
            if ((int) ($c->command('FetchAttentionState')['state'] ?? 0) == 2) {
                $this->SendDebug('PowerOn', 'Bildschirmschoner läuft, sende Menü', 0);
                $c->command('_hidC', ['_hBtS' => 1, '_hidC' => self::HID_MENU]);
                $c->command('_hidC', ['_hBtS' => 2, '_hidC' => self::HID_MENU]);
            }
            return true;
        }) !== null;
        if ($ok) {
            $this->SetValue('POWER', true);
            $this->SetValue('STATUS', 3);
        }
        return $ok;
    }

    /** In den Ruhezustand schicken. */
    public function PowerOff(): bool
    {
        $ok = $this->ausfuehren(fn (Connection $c) => $c->command('_hidC', ['_hBtS' => 2, '_hidC' => self::HID_SLEEP])) !== null;
        if ($ok) {
            $this->SetValue('POWER', false);
            $this->SetValue('STATUS', 1);
            $this->SetValue('APP', '');
            $this->schalterSetzen('');
        }
        return $ok;
    }

    /**
     * App starten (weckt das Apple TV vorher; ein Bildschirmschoner endet mit
     * dem Start von selbst). $Bundle ist die App-Kennung,
     * z. B. com.netflix.Netflix, oder eine URL (z. B. https://...).
     */
    public function LaunchApp(string $Bundle): bool
    {
        $schluessel = preg_match('#^[a-z][a-z0-9+.-]*:#i', $Bundle) ? '_urlS' : '_bundleID';
        $ok = $this->ausfuehren(function (Connection $c) use ($schluessel, $Bundle) {
            $c->command('_hidC', ['_hBtS' => 2, '_hidC' => self::HID_WAKE]);
            return $c->command('_launchApp', [$schluessel => $Bundle]);
        }) !== null;
        if ($ok) {
            $this->SetValue('POWER', true);
            $this->SetValue('STATUS', 3);
            $this->SetValue('APP', isset($this->apps()[$Bundle]) ? $Bundle : '');
            $this->schalterSetzen($Bundle);
        }
        return $ok;
    }

    /**
     * Installierte Apps vom Apple TV holen (Kennung => Name). Aktualisiert
     * die Auswahl der Variable "App" und die Liste im Formular.
     */
    public function GetApps(): array
    {
        $apps = $this->ausfuehren(fn (Connection $c) => $c->command('FetchLaunchableApplicationsEvent'));
        if ($apps === null) {
            return [];
        }
        $apps = array_filter($apps, 'is_string');
        asort($apps, SORT_NATURAL | SORT_FLAG_CASE);
        $this->WriteAttributeString('Apps', json_encode($apps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Die Liste im Formular haengt an der Eigenschaft: neue Apps dort
        // ergaenzen, die Haken bleiben erhalten.
        $liste = json_encode($this->schalterListe());
        if ($liste !== $this->ReadPropertyString('AppSwitches')) {
            IPS_SetProperty($this->InstanceID, 'AppSwitches', $liste);
            IPS_ApplyChanges($this->InstanceID);
        } else {
            $this->registerVariables();
        }
        $this->ReloadForm();
        return $apps;
    }

    /**
     * Zuletzt geholte App-Liste (Kennung => Name), ohne Verbindung zum
     * Apple TV. Fuer Skripte, die die Liste oft brauchen.
     */
    public function GetKnownApps(): array
    {
        return $this->apps();
    }

    /**
     * Taste der Siri Remote druecken und loslassen (pyatv HidCommand:
     * 1-4 Hoch/Runter/Links/Rechts, 5 Menue/Zurueck, 6 OK, 7 TV/Home,
     * 8/9 Lauter/Leiser, 14 Wiedergabe/Pause).
     */
    public function SendKey(int $Key): bool
    {
        return $this->ausfuehren(function (Connection $c) use ($Key) {
            $c->command('_hidC', ['_hBtS' => 1, '_hidC' => $Key]);
            return $c->command('_hidC', ['_hBtS' => 2, '_hidC' => $Key]);
        }) !== null;
    }

    /** Wiedergabe steuern (pyatv MediaControlCommand: 1 Play, 2 Pause, 3 naechster, 4 voriger Titel). */
    public function MediaCommand(int $Command): bool
    {
        return $this->ausfuehren(fn (Connection $c) => $c->command('_mcc', ['_mcc' => $Command])) !== null;
    }

    public function IsPaired(): bool
    {
        return $this->ReadAttributeString('Pairing') !== '';
    }

    /** Kopplung starten. Das Apple TV zeigt danach eine PIN fuer SubmitPin. */
    public function StartPairing(): void
    {
        $this->SetBuffer('PIN', '');
        $this->kopplungsInfo('Verbinde mit dem Apple TV ...');
        $this->RegisterOnceTimer('Pairing', 'ATV_RunPairing($_IPS[\'TARGET\']);');
    }

    /** PIN vom Bildschirm an die laufende Kopplung uebergeben. */
    public function SubmitPin(string $PIN): void
    {
        $PIN = trim($PIN);
        if (!preg_match('/^\d{4}$/', $PIN)) {
            throw new Exception('Die PIN hat vier Ziffern');
        }
        $this->SetBuffer('PIN', $PIN);
    }

    /** Kopplung in der Instanz loeschen. Auf dem Apple TV bleibt der Eintrag, bis man ihn dort entfernt. */
    public function Unpair(): void
    {
        $this->WriteAttributeString('Pairing', '');
        $this->kopplungsInfo('Kopplung geloescht. Auf dem Apple TV unter Einstellungen > Fernbedienungen und Geraete entfernen.');
        $this->SetStatus(self::S_NICHT_GEKOPPELT);
    }

    /** Intern: Kopplung im eigenen Thread (gestartet von StartPairing). */
    public function RunPairing(): void
    {
        $sperre = 'ATV_PAIR_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sperre, 0)) {
            return;   // laeuft schon
        }
        try {
            $c = $this->oeffnen(true);
            $zustand = $c->pairStart();
            $this->kopplungsInfo('Das Apple TV zeigt eine PIN. Eintragen und "PIN senden" (2 Minuten Zeit).');
            $pin = '';
            for ($i = 0; $i < 120 && $pin === ''; $i++) {
                IPS_Sleep(1000);
                $pin = $this->GetBuffer('PIN');
            }
            if ($pin === '') {
                throw new Exception('keine PIN eingetragen (Zeit abgelaufen)');
            }
            $this->kopplungsInfo('Prüfe die PIN ...');
            $kopplung = $c->pairFinish($zustand, $pin, $this->clientName());
            unset($c);
            $this->WriteAttributeString('Pairing', $kopplung);
            $this->SetStatus(IS_ACTIVE);
            $apps = $this->GetApps();
            $this->kopplungsInfo('Gekoppelt am ' . date('d.m.Y H:i') . ', ' . count($apps) . ' Apps.');
        } catch (Exception $e) {
            $this->kopplungsInfo('Kopplung fehlgeschlagen: ' . $e->getMessage());
        } finally {
            $this->SetBuffer('PIN', '');
            IPS_SemaphoreLeave($sperre);
        }
    }

    // ===================== Verbindung =====================

    /**
     * Fuehrt $befehl auf einer angemeldeten Verbindung aus. Gibt dessen
     * Ergebnis zurueck oder null bei Fehler; mit $warnen als Warnung fuer
     * den Aufrufer (Formular, Skript, Visualisierung).
     */
    private function ausfuehren(callable $befehl, bool $warnen = true)
    {
        $sperre = 'ATV_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sperre, 15000)) {
            return $this->fehler('Apple TV ist gerade belegt', $warnen);
        }
        try {
            if (!$this->IsPaired()) {
                $this->SetStatus(self::S_NICHT_GEKOPPELT);
                throw new Exception('Apple TV ist nicht gekoppelt');
            }
            $c = $this->oeffnen(false);
            try {
                $c->verify($this->ReadAttributeString('Pairing'), $this->clientName());
            } catch (NotConfirmedException $e) {
                $this->SetStatus(self::S_ABGELEHNT);
                throw $e;
            }
            $ergebnis = $befehl($c);
            if ($this->GetStatus() != IS_ACTIVE) {
                $this->SetStatus(IS_ACTIVE);
            }
            return $ergebnis;
        } catch (Exception $e) {
            return $this->fehler($e->getMessage(), $warnen);
        } finally {
            IPS_SemaphoreLeave($sperre);
        }
    }

    private function fehler(string $text, bool $warnen)
    {
        $this->SendDebug('Fehler', $text, 0);
        if ($warnen) {
            trigger_error($text, E_USER_WARNING);
        }
        return null;
    }

    /**
     * Verbindung zur eingetragenen Adresse. Antwortet dort nichts, sucht
     * die Instanz das Apple TV ueber den Geraetenamen per mDNS und merkt
     * sich die neue Adresse bis zum naechsten ApplyChanges.
     */
    private function oeffnen(bool $neuSuchen): Connection
    {
        $adressen = [];
        $gefunden = $this->ReadAttributeString('FoundAddress');
        if ($gefunden !== '' && !$neuSuchen) {
            $adressen[] = $gefunden;
        }
        if ($this->ReadPropertyString('Host') !== '') {
            $adressen[] = $this->ReadPropertyString('Host') . ':' . $this->ReadPropertyInteger('Port');
        }
        $fehler = 'keine Adresse eingetragen';
        foreach (array_unique($adressen) as $adresse) {
            try {
                return $this->verbinden($adresse);
            } catch (Exception $e) {
                $fehler = $e->getMessage();
            }
        }

        $name = $this->ReadPropertyString('DeviceName');
        if ($name !== '') {
            foreach (Discovery::search() as $g) {
                if (strcasecmp($g['name'], $name) == 0) {
                    $adresse = $g['host'] . ':' . $g['port'];
                    $c = $this->verbinden($adresse);
                    $this->WriteAttributeString('FoundAddress', $adresse);
                    $this->SendDebug('Suche', $name . ' gefunden unter ' . $adresse, 0);
                    return $c;
                }
            }
            $fehler .= ', "' . $name . '" per mDNS nicht gefunden';
        }
        $this->SetStatus(self::S_NICHT_ERREICHBAR);
        throw new Exception('Apple TV nicht erreichbar (' . $fehler . ')');
    }

    private function verbinden(string $adresse): Connection
    {
        $teile = explode(':', $adresse);
        $c = new Connection($teile[0], (int) ($teile[1] ?? 49153));
        $c->debug = fn (string $text) => $this->SendDebug('Companion', $text, 0);
        return $c;
    }

    private function clientName(): string
    {
        $name = trim($this->ReadPropertyString('ClientName'));
        return $name === '' ? 'Symcon' : $name;
    }

    private function kopplungsInfo(string $text): void
    {
        $this->WriteAttributeString('PairingInfo', $text);
        $this->SendDebug('Kopplung', $text, 0);
        $this->UpdateFormField('PairingInfo', 'caption', $text);
    }

    // ===================== Variablen =====================

    private function registerVariables(): void
    {
        $this->RegisterVariableBoolean('POWER', 'Eingeschaltet', self::pSchalter('Power'), 1);
        $this->EnableAction('POWER');

        $this->RegisterVariableInteger('STATUS', 'Zustand', self::pAufz(self::ZUSTAND, 'Information'), 2);

        $optionen = ['' => '-'] + $this->apps();
        $this->RegisterVariableString('APP', 'App', self::pAufz($optionen, 'Menu'), 3);
        $this->EnableAction('APP');

        // Schalter je angekreuzter App, z. B. fuer die HomeKit-Bridge
        $soll = [];
        $pos = 10;
        foreach (json_decode($this->ReadPropertyString('AppSwitches'), true) ?: [] as $zeile) {
            if (empty($zeile['Switch']) || ($zeile['Bundle'] ?? '') === '') {
                continue;
            }
            $ident = self::schalterIdent($zeile['Bundle']);
            $soll[$ident] = true;
            $this->RegisterVariableBoolean($ident, $zeile['Name'] ?: $zeile['Bundle'], self::pSchalter('Tv'), $pos++);
            $this->EnableAction($ident);
        }
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $id) {
            $ident = IPS_GetObject($id)['ObjectIdent'];
            if (str_starts_with($ident, 'APP_') && !isset($soll[$ident])) {
                $this->UnregisterVariable($ident);
            }
        }
    }

    /** Schalter der gestarteten App an, alle anderen aus. */
    private function schalterSetzen(string $bundle): void
    {
        $an = $bundle === '' ? '' : self::schalterIdent($bundle);
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $id) {
            $ident = IPS_GetObject($id)['ObjectIdent'];
            if (str_starts_with($ident, 'APP_')) {
                $this->SetValue($ident, $ident === $an);
            }
        }
    }

    private function apps(): array
    {
        return json_decode($this->ReadAttributeString('Apps'), true) ?: [];
    }

    /** Alle bekannten Apps mit dem Haken aus der Eigenschaft. */
    private function schalterListe(): array
    {
        $haken = [];
        $namen = [];
        foreach (json_decode($this->ReadPropertyString('AppSwitches'), true) ?: [] as $zeile) {
            $haken[$zeile['Bundle']] = !empty($zeile['Switch']);
            $namen[$zeile['Bundle']] = $zeile['Name'];
        }
        // Angekreuzte Apps bleiben stehen, auch wenn sie nicht mehr installiert sind
        $alle = $this->apps() + array_filter($namen, fn ($b) => $haken[$b], ARRAY_FILTER_USE_KEY);
        $liste = [];
        foreach ($alle as $bundle => $name) {
            $liste[] = ['Name' => $name, 'Bundle' => $bundle, 'Switch' => $haken[$bundle] ?? false];
        }
        return $liste;
    }

    private static function schalterIdent(string $bundle): string
    {
        return sprintf('APP_%08x', crc32($bundle));
    }

    private static function pAufz(array $optionen, string $icon): array
    {
        $o = [];
        foreach ($optionen as $wert => $text) {
            $o[] = ['Value' => $wert, 'Caption' => $text, 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        return ['PRESENTATION' => self::P_AUFZ, 'OPTIONS' => json_encode($o), 'ICON' => $icon, 'LAYOUT' => 0];
    }

    private static function pSchalter(string $icon): array
    {
        return ['PRESENTATION' => self::P_SCHALTER, 'ICON_TRUE' => $icon, 'ICON_FALSE' => $icon, 'USE_ICON_FALSE' => false, 'USAGE_TYPE' => 0];
    }

    /** Setzt Felder in Formular-Elementen (auch verschachtelt) nach Name. */
    private function formularFuellen(array $elemente, array $werte): array
    {
        foreach ($elemente as &$e) {
            if (isset($e['name'], $werte[$e['name']])) {
                $e = array_merge($e, $werte[$e['name']]);
            }
            if (isset($e['items'])) {
                $e['items'] = $this->formularFuellen($e['items'], $werte);
            }
        }
        return $elemente;
    }
}
