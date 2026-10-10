<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Companion.php';

use SymconAppleTV\Discovery;

/**
 * Sucht Apple TVs per mDNS (_companion-link._tcp) und legt mit einem Klick
 * die Geraete-Instanz an. Bereits angelegte Instanzen erkennt er am
 * Geraetenamen oder an der Adresse; nicht gefundene stehen trotzdem in der
 * Liste (grau), damit man sie wiederfindet.
 *
 * Steht Symcon in einem anderen Netz als das Apple TV, muss ein
 * mDNS-Reflektor die Anfrage durchreichen (z. B. UniFi "Multicast DNS").
 */
class AppleTVConfigurator extends IPSModule
{
    private const GERAET = '{87420B13-61AC-46AB-8AE8-911160F32543}';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('SearchTime', 2);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(IS_ACTIVE);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $form['actions'][0]['values'] = $this->eintraege();
        return json_encode($form);
    }

    // ===================== Oeffentliche Funktionen =====================

    /** Apple TVs im Netz: [['name', 'host', 'port', 'model'], ...] */
    public function Search(): array
    {
        try {
            $geraete = Discovery::search(max(1, min(10, $this->ReadPropertyInteger('SearchTime'))));
        } catch (Exception $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);
            return [];
        }
        $this->SendDebug('Suche', json_encode($geraete), 0);
        return $geraete;
    }

    // ===================== Liste =====================

    private function eintraege(): array
    {
        $instanzen = [];
        foreach (IPS_GetInstanceListByModuleID(self::GERAET) as $id) {
            $instanzen[$id] = [
                'name' => (string) IPS_GetProperty($id, 'DeviceName'),
                'host' => (string) IPS_GetProperty($id, 'Host'),
            ];
        }

        $werte = [];
        foreach ($this->Search() as $g) {
            $id = 0;
            foreach ($instanzen as $iid => $i) {
                if (($i['name'] !== '' && strcasecmp($i['name'], $g['name']) == 0)
                    || ($i['name'] === '' && $i['host'] === $g['host'])) {
                    $id = $iid;
                    break;
                }
            }
            if ($id > 0) {
                unset($instanzen[$id]);
            }
            $werte[] = [
                'name'       => $g['name'],
                'model'      => Discovery::modelName($g['model']),
                'address'    => $g['host'] . ':' . $g['port'],
                'instanceID' => $id,
                'create'     => [
                    'moduleID'      => self::GERAET,
                    'configuration' => [
                        'Host'       => $g['host'],
                        'Port'       => $g['port'],
                        'DeviceName' => $g['name'],
                    ],
                    'name'          => 'Apple TV ' . $g['name'],
                ],
            ];
        }

        // Angelegt, aber gerade nicht gefunden
        foreach ($instanzen as $iid => $i) {
            $werte[] = [
                'name'       => $i['name'] !== '' ? $i['name'] : IPS_GetName($iid),
                'model'      => '',
                'address'    => $i['host'],
                'instanceID' => $iid,
            ];
        }
        return $werte;
    }
}
