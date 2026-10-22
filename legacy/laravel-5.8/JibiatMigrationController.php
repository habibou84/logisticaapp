<?php

/*
 * Migration vers Jibiat — module pour l'ancienne application (Laravel 5.8, PHP 7.1 ou plus, extension curl).
 *
 * Envoie au nouveau serveur les e-commerçants (table clients) et les livreurs (livreurs) avec leurs comptes
 * de connexion (users de type « client » ou « livreur »). Mêmes numéros, mêmes mots de passe. Les comptes de
 * l'équipe (autres types) ne sont pas envoyés : ils se recréent sur Jibiat. Rien n'est modifié ni supprimé
 * dans l'ancienne base.
 *
 * Installation :
 *   1. Copier ce fichier dans app/Http/Controllers/JibiatMigrationController.php
 *   2. Ajouter à la fin de routes/web.php :
 *
 *        Route::middleware('auth')->group(function () {
 *            Route::get('jibiat-migration', 'JibiatMigrationController@index');
 *            Route::post('jibiat-migration/send', 'JibiatMigrationController@send');
 *        });
 *
 *   3. Se connecter avec un compte administrateur et ouvrir https://ancienne-adresse/jibiat-migration
 *   4. Coller l'adresse de l'espace Jibiat et la clé de reprise (Jibiat › Paramètres › Reprise de
 *      l'ancienne application), cliquer sur « Simuler », puis sur « Envoyer ».
 *
 * Relancer l'envoi ne crée pas de doublon : un numéro déjà présent sur Jibiat est ignoré.
 * Après la migration, retirer les deux routes et ce fichier.
 */

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class JibiatMigrationController extends Controller
{
    /** Lignes envoyées par paquet */
    const BATCH = 200;

    /** Étapes d'envoi et type de compte (« users.usertype ») de chacune */
    const STEPS = ['clients' => 'client', 'livreurs' => 'livreur'];

    public function index()
    {
        $this->authorizeAccess();

        return response($this->page([
            'clients' => DB::table('clients')->count(),
            'livreurs' => DB::table('livreurs')->count(),
            'team' => DB::table('users')->whereNotIn(DB::raw('LOWER(TRIM(usertype))'), array_values(self::STEPS))->orWhereNull('usertype')->count(),
        ]));
    }

    public function send(Request $request)
    {
        $this->authorizeAccess();

        $url = rtrim(trim((string) $request->input('url')), '/');
        $key = trim((string) $request->input('key'));
        $step = (string) $request->input('step');
        $offset = max(0, (int) $request->input('offset'));
        $dryRun = $request->input('dry_run') === '1';

        if (! preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $url)) {
            return $this->error('Adresse invalide : saisissez l\'adresse de votre espace Jibiat, par exemple https://votre-entreprise.jibiat.com');
        }
        if (strpos($key, 'jbm_') !== 0) {
            return $this->error('Clé de reprise invalide : copiez-la depuis Jibiat › Paramètres › Reprise de l\'ancienne application.');
        }
        if (! isset(self::STEPS[$step])) {
            return $this->error('Étape inconnue.');
        }
        if (! function_exists('curl_init')) {
            return $this->error('L\'extension PHP « curl » est absente de ce serveur : demandez à votre hébergeur de l\'activer.');
        }

        list($payload, $sent, $total) = $this->batch($step, $offset);
        $payload['step'] = $step;
        $payload['dry_run'] = $dryRun;

        try {
            $response = $this->post($url.'/api/legacy-import', $key, $payload);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 502);
        }

        return response()->json([
            'step' => $step,
            'sent' => $sent,
            'total' => $total,
            'next_offset' => $offset + self::BATCH < $total ? $offset + self::BATCH : null,
            'report' => isset($response['data']) ? $response['data'] : null,
        ]);
    }

    /**
     * Réservé à l'équipe : les comptes des e-commerçants et des livreurs n'ouvrent pas cette page.
     * Adaptez cette vérification si votre application distingue autrement les administrateurs.
     */
    protected function authorizeAccess()
    {
        $user = auth()->user();
        $type = strtolower((string) (isset($user->usertype) ? $user->usertype : ''));

        if ($user === null || strpos($type, 'client') !== false || strpos($type, 'livreur') !== false) {
            abort(403, 'Page réservée aux administrateurs.');
        }
    }

    /**
     * Paquet d'une étape : les fiches et les comptes qui leur sont liés (users.id = fiche.user_id,
     * ou users.client_id / users.livreur_id = fiche.id). Un compte d'un autre type (l'administrateur,
     * rattaché à une fiche client) est envoyé sans son mot de passe : Jibiat ignore alors la fiche.
     */
    private function batch($step, $offset)
    {
        $table = $step;
        $link = $step === 'clients' ? 'client_id' : 'livreur_id';
        $total = DB::table($table)->count();
        $rows = DB::table($table)->orderBy('id')->offset($offset)->limit(self::BATCH)->get();
        $ids = $rows->pluck('id')->all();
        $userIds = $rows->pluck('user_id')->filter()->all();

        $users = DB::table('users')->where(function ($query) use ($link, $ids, $userIds) {
            $query->whereIn($link, $ids ?: [0]);
            if ($userIds) {
                $query->orWhereIn('id', $userIds);
            }
        })->get()->map(function ($user) use ($step) {
            if (strtolower(trim((string) $user->usertype)) !== self::STEPS[$step]) {
                $user->password = null;
            }

            return $user;
        });

        return [[$table => $this->arrays($rows), 'users' => $this->arrays($users)], count($rows), $total];
    }

    /** Lignes en tableaux, textes en UTF-8 (certaines tables anciennes sont en latin1) */
    private function arrays($rows)
    {
        return $rows->map(function ($row) {
            $values = [];
            foreach ((array) $row as $column => $value) {
                $values[$column] = is_string($value) && ! mb_check_encoding($value, 'UTF-8') ? utf8_encode($value) : $value;
            }

            return $values;
        })->values()->all();
    }

    private function post($endpoint, $key, array $payload)
    {
        $json = json_encode($payload);
        if ($json === false) {
            throw new RuntimeException('Données illisibles dans l\'ancienne base : '.json_last_error_msg());
        }

        $curl = curl_init($endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer '.$key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $failure = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('Connexion impossible au serveur Jibiat ('.$failure.'). Vérifiez l\'adresse, ou demandez à votre hébergeur d\'autoriser les connexions sortantes en HTTPS.');
        }

        $data = json_decode($body, true);
        if ($status >= 400 || ! is_array($data)) {
            $message = is_array($data) && isset($data['message']) ? $data['message'] : 'Réponse inattendue du serveur Jibiat (code '.$status.').';

            throw new RuntimeException($message);
        }

        return $data;
    }

    private function error($message, $status = 422)
    {
        return response()->json(['message' => $message], $status);
    }

    private function page(array $counts)
    {
        $token = csrf_token();
        $sendUrl = url('jibiat-migration/send');
        $counts = json_encode($counts);

        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Migration vers Jibiat</title>
<style>
  body { font-family: system-ui, -apple-system, sans-serif; background: #f1f5f9; color: #0f172a; margin: 0; padding: 24px 16px; }
  main { max-width: 760px; margin: 0 auto; }
  .card { background: #fff; border-radius: 12px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
  h1 { font-size: 22px; margin: 0 0 4px; } h2 { font-size: 16px; margin: 0 0 12px; }
  p { color: #475569; line-height: 1.5; }
  label { display: block; font-weight: 600; font-size: 14px; margin: 12px 0 4px; }
  input { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 15px; }
  button { padding: 10px 18px; border: 0; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; margin: 16px 8px 0 0; }
  .primary { background: #047857; color: #fff; } .secondary { background: #e2e8f0; color: #0f172a; }
  button:disabled { opacity: .5; cursor: wait; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; } td, th { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
  .error { color: #b91c1c; font-weight: 600; } .ok { color: #047857; font-weight: 600; }
  .muted { color: #64748b; font-size: 13px; }
</style>
</head>
<body>
<main>
  <div class="card">
    <h1>Migration vers Jibiat</h1>
    <p>Envoie les e-commerçants et les livreurs vers votre espace Jibiat. Chacun garde son numéro et son mot de passe. Les comptes de l'équipe ne sont pas envoyés : recréez-les sur Jibiat. Rien n'est modifié dans cette application.</p>
    <table id="counts"></table>
  </div>
  <div class="card">
    <label for="url">Adresse de votre espace Jibiat</label>
    <input id="url" placeholder="https://votre-entreprise.jibiat.com" autocomplete="off">
    <label for="key">Clé de reprise</label>
    <input id="key" placeholder="jbm_…" autocomplete="off">
    <p class="muted">La clé se crée dans Jibiat › Paramètres › Reprise de l'ancienne application. Elle est valable quelques jours.</p>
    <button class="secondary" id="simulate">Simuler (rien n'est enregistré)</button>
    <button class="primary" id="send">Envoyer</button>
    <p id="status"></p>
  </div>
  <div class="card" id="report" hidden>
    <h2 id="report-title">Résultat</h2>
    <table id="totals"></table>
    <h2 style="margin-top:20px">Non importés</h2>
    <table id="skipped"></table>
    <h2 style="margin-top:20px">À vérifier</h2>
    <table id="warnings"></table>
  </div>
</main>
<script>
(function () {
  var counts = {$counts};
  var labels = { clients: 'E-commerçants (clients)', livreurs: 'Livreurs', team: 'Comptes de l\'équipe (non envoyés, à recréer sur Jibiat)' };
  var steps = ['clients', 'livreurs'];
  var el = function (id) { return document.getElementById(id); };

  function row(table, cells, head) {
    var tr = document.createElement('tr');
    cells.forEach(function (text) { var td = document.createElement(head ? 'th' : 'td'); td.textContent = text; tr.appendChild(td); });
    table.appendChild(tr);
  }
  ['clients', 'livreurs', 'team'].forEach(function (s) { row(el('counts'), [labels[s], String(counts[s])]); });

  try { el('url').value = localStorage.getItem('jibiat-url') || ''; } catch (e) {}

  function call(params) {
    var body = new URLSearchParams(params);
    return fetch('{$sendUrl}', {
      method: 'POST', credentials: 'same-origin', body: body,
      headers: { 'X-CSRF-TOKEN': '{$token}', 'Accept': 'application/json' }
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (data) {
        if (!r.ok) throw new Error(data.message || ('Erreur ' + r.status));
        return data;
      });
    });
  }

  function run(dryRun) {
    var url = el('url').value.trim(), key = el('key').value.trim();
    try { localStorage.setItem('jibiat-url', url); } catch (e) {}
    var totals = { merchants: 0, merchant_users: 0, couriers: 0, couriers_suspended: 0 };
    var skipped = [], warnings = [];
    el('simulate').disabled = el('send').disabled = true;
    el('status').className = '';

    var i = 0, offset = 0;
    function next() {
      if (i >= steps.length) return finish();
      var step = steps[i];
      if (!counts[step]) { i++; offset = 0; return next(); }
      el('status').textContent = labels[step] + ' : ' + Math.min(offset, counts[step]) + ' / ' + counts[step] + '…';
      return call({ url: url, key: key, step: step, offset: offset, dry_run: dryRun ? '1' : '0' }).then(function (data) {
        var report = data.report || { counts: {}, skipped: [], warnings: [] };
        Object.keys(totals).forEach(function (k) { totals[k] += report.counts[k] || 0; });
        skipped = skipped.concat(report.skipped || []);
        warnings = warnings.concat(report.warnings || []);
        if (data.next_offset === null) { i++; offset = 0; } else { offset = data.next_offset; }
        return next();
      });
    }

    function finish() {
      el('report').hidden = false;
      el('report-title').textContent = dryRun ? 'Simulation (rien n\'a été enregistré)' : 'Envoi terminé';
      el('totals').innerHTML = ''; el('skipped').innerHTML = ''; el('warnings').innerHTML = '';
      row(el('totals'), ['E-commerçants importés', String(totals.merchants)]);
      row(el('totals'), ['Autres comptes des e-commerçants', String(totals.merchant_users)]);
      row(el('totals'), ['Livreurs importés', totals.couriers + (totals.couriers_suspended ? ' (dont ' + totals.couriers_suspended + ' suspendu(s))' : '')]);
      [['skipped', skipped], ['warnings', warnings]].forEach(function (pair) {
        if (!pair[1].length) { row(el(pair[0]), ['Aucun']); return; }
        row(el(pair[0]), ['Type', 'N° ancien', 'Nom', 'Raison'], true);
        pair[1].forEach(function (r) { row(el(pair[0]), [r.type, r.id, r.name, r.reason]); });
      });
      el('status').className = 'ok';
      el('status').textContent = dryRun ? 'Simulation terminée : vérifiez le résultat, puis cliquez sur « Envoyer ».' : 'Envoi terminé. Vous pouvez relancer sans risque de doublon.';
    }

    next().catch(function (e) {
      el('status').className = 'error';
      el('status').textContent = e.message;
    }).then(function () { el('simulate').disabled = el('send').disabled = false; });
  }

  el('simulate').addEventListener('click', function () { run(true); });
  el('send').addEventListener('click', function () {
    if (confirm('Envoyer les comptes vers Jibiat ?')) run(false);
  });
})();
</script>
</body>
</html>
HTML;
    }
}
