<?php
// ============================================================================
// LITERAKI ONLINE (1-PLIKOWY SERWER PHP + JS)
// ============================================================================
session_start();
error_reporting(0);

$dataDir = __DIR__ . '/gry_literaki';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0777, true);
}

// Czyszczenie starych gier (starszych niż 24h)
foreach (glob("$dataDir/*.json") as $file) {
    if (filemtime($file) < time() - 86400) @unlink($file);
}

// Konfiguracja polskich płytek (wartość, ilość) - standard Scrabble PL (100 płytek)
function getTilePool() {
    $dist = [
        'A' => [1, 9], 'I' => [1, 8], 'E' => [1, 7], 'O' => [1, 6], 'N' => [1, 5],
        'Z' => [1, 5], 'R' => [1, 4], 'S' => [1, 4], 'W' => [1, 4], 'Y' => [2, 4],
        'C' => [2, 3], 'D' => [2, 3], 'K' => [2, 3], 'L' => [2, 3], 'M' => [2, 3],
        'P' => [2, 3], 'T' => [2, 3], 'B' => [3, 2], 'G' => [3, 2], 'H' => [3, 2],
        'J' => [3, 2], 'Ł' => [3, 2], 'U' => [3, 2], 'Ą' => [5, 1], 'Ę' => [5, 1],
        'F' => [5, 1], 'Ó' => [5, 1], 'Ś' => [5, 1], 'Ż' => [5, 1], 'Ć' => [6, 1],
        'Ń' => [7, 1], 'Ź' => [9, 1], '_' => [0, 2] // '_' to blank (mydło)
    ];
    $pool = [];
    foreach ($dist as $char => $info) {
        for ($i = 0; $i < $info[1]; $i++) {
            $pool[] = ['l' => $char, 'p' => $info[0]];
        }
    }
    shuffle($pool);
    return $pool;
}

// Układ premii na planszy 15x15 (TW=3x słowo, DW=2x słowo, TL=3x litera, DL=2x litera)
function getBoardBonuses() {
    $b = array_fill(0, 15, array_fill(0, 15, ''));
    $tw = [[0,0],[0,7],[0,14],[7,0],[7,14],[14,0],[14,7],[14,14]];
    $dw = [[1,1],[2,2],[3,3],[4,4],[1,13],[2,12],[3,11],[4,10],[13,1],[12,2],[11,3],[10,4],[13,13],[12,12],[11,11],[10,10],[7,7]];
    $tl = [[1,5],[1,9],[5,1],[5,5],[5,9],[5,13],[9,1],[9,5],[9,9],[9,13],[13,5],[13,9]];
    $dl = [[0,3],[0,11],[2,6],[2,8],[3,0],[3,7],[3,14],[6,2],[6,6],[6,8],[6,12],[7,3],[7,11],[8,2],[8,6],[8,8],[8,12],[11,0],[11,7],[11,14],[12,6],[12,8],[14,3],[14,11]];
    foreach ($tw as $c) $b[$c[0]][$c[1]] = 'TW';
    foreach ($dw as $c) $b[$c[0]][$c[1]] = 'DW';
    foreach ($tl as $c) $b[$c[0]][$c[1]] = 'TL';
    foreach ($dl as $c) $b[$c[0]][$c[1]] = 'DL';
    return $b;
}

function finalizeGameState(&$state) {
    if (($state['status'] ?? '') === 'finished') {
        return;
    }

    $state['status'] = 'finished';
    $unusedSummary = [];

    foreach ($state['players'] as &$player) {
        $unusedTotal = 0;
        foreach ($player['rack'] as $tile) {
            $unusedTotal += (int)($tile['p'] ?? 0);
        }

        $player['score'] -= $unusedTotal;
        if ($unusedTotal > 0) {
            $unusedSummary[] = $player['nick'] . ' (-' . $unusedTotal . ' pkt)';
        }
        $player['rack'] = [];
    }
    unset($player);

    if (!empty($unusedSummary)) {
        $state['log'][] = 'Niewykorzystane litery: ' . implode(', ', $unusedSummary) . '.';
    }
}

// ============================================================================
// OBSŁUGA API (AJAX)
// ============================================================================
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['api'];
    $roomId = preg_replace('/[^a-zA-Z0-9]/', '', $_REQUEST['room'] ?? '');
    $roomFile = "$dataDir/$roomId.json";

    if ($action === 'create') {
        $nick = trim(mb_substr($_POST['nick'] ?? 'Gracz 1', 0, 15));
        $maxPlayers = max(2, min(4, (int)($_POST['max'] ?? 4)));
        $newRoom = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 5);
        $playerId = bin2hex(random_bytes(8));
        $_SESSION['pid_' . $newRoom] = $playerId;

        $pool = getTilePool();
        $rack = array_splice($pool, 0, 7);

        $state = [
            'room' => $newRoom,
            'maxPlayers' => $maxPlayers,
            'status' => 'waiting', // waiting, playing, finished
            'turn' => 0,
            'passes' => 0,
            'pool' => $pool,
            'board' => array_fill(0, 15, array_fill(0, 15, null)),
            'players' => [
                ['id' => $playerId, 'nick' => $nick ?: 'Gracz 1', 'score' => 0, 'rack' => $rack]
            ],
            'log' => ["Gracz $nick utworzył pokój ($maxPlayers os.)."],
            'version' => 1
        ];
        file_put_contents("$dataDir/$newRoom.json", json_encode($state, JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true, 'room' => $newRoom]);
        exit;
    }

    if (!file_exists($roomFile)) {
        echo json_encode(['ok' => false, 'err' => 'Pokój nie istnieje.']);
        exit;
    }

    $fp = fopen($roomFile, 'r+');
    flock($fp, LOCK_EX);
    $state = json_decode(stream_get_contents($fp), true);
    $pid = $_SESSION['pid_' . $roomId] ?? '';

    // Dołączanie do pokoju
    if ($action === 'join') {
        $nick = trim(mb_substr($_POST['nick'] ?? 'Gracz', 0, 15));
        $found = false;
        foreach ($state['players'] as $p) {
            if ($p['id'] === $pid) $found = true;
        }
        if (!$found && count($state['players']) < $state['maxPlayers'] && $state['status'] === 'waiting') {
            $newPid = bin2hex(random_bytes(8));
            $_SESSION['pid_' . $roomId] = $newPid;
            $rack = array_splice($state['pool'], 0, 7);
            $state['players'][] = ['id' => $newPid, 'nick' => $nick ?: 'Gracz ' . (count($state['players']) + 1), 'score' => 0, 'rack' => $rack];
            $state['log'][] = "$nick dołączył do gry.";
            if (count($state['players']) === $state['maxPlayers']) {
                $state['status'] = 'playing';
                $state['log'][] = "Komplet graczy! Rozpoczyna " . $state['players'][0]['nick'] . ".";
            }
            $state['version']++;
            ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE));
        }
        flock($fp, LOCK_UN); fclose($fp);
        echo json_encode(['ok' => true]);
        exit;
    }

    // Wymuszenie startu przed kompletem (np. 3 osoby zamiast 4)
    if ($action === 'force_start' && $state['status'] === 'waiting' && count($state['players']) >= 2) {
        if ($state['players'][0]['id'] === $pid) {
            $state['status'] = 'playing';
            $state['log'][] = "Host rozpoczął grę w " . count($state['players']) . " os.";
            $state['version']++;
            ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE));
        }
        flock($fp, LOCK_UN); fclose($fp);
        echo json_encode(['ok' => true]);
        exit;
    }

    // Pobranie stanu
    if ($action === 'state') {
        flock($fp, LOCK_UN); fclose($fp);
        $myIndex = -1;
        foreach ($state['players'] as $idx => $p) {
            if ($p['id'] === $pid) $myIndex = $idx;
        }
        // Ukryj stojaki innych graczy oraz worek
        $publicPlayers = [];
        foreach ($state['players'] as $idx => $p) {
            $publicPlayers[] = [
                'nick' => $p['nick'],
                'score' => $p['score'],
                'tilesCount' => count($p['rack']),
                'isMe' => ($idx === $myIndex)
            ];
        }
        echo json_encode([
            'ok' => true,
            'room' => $state['room'],
            'status' => $state['status'],
            'maxPlayers' => $state['maxPlayers'],
            'turn' => $state['turn'],
            'myIndex' => $myIndex,
            'myRack' => $myIndex >= 0 ? $state['players'][$myIndex]['rack'] : [],
            'poolCount' => count($state['pool']),
            'board' => $state['board'],
            'players' => $publicPlayers,
            'log' => $state['log'],
            'version' => $state['version']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Sprawdzenie czy kolej gracza
    $currentPlayer = $state['players'][$state['turn']] ?? null;
    if (!$currentPlayer || $currentPlayer['id'] !== $pid || $state['status'] !== 'playing') {
        flock($fp, LOCK_UN); fclose($fp);
        echo json_encode(['ok' => false, 'err' => 'To nie Twoja tura!']);
        exit;
    }

    // PAS
    if ($action === 'pass') {
        $state['passes']++;
        $state['log'][] = $currentPlayer['nick'] . " spasował.";
        if ($state['passes'] >= count($state['players']) * 2) {
            $state['log'][] = "Koniec gry (wszyscy spasowali dwukrotnie).";
            finalizeGameState($state);
        } else {
            $state['turn'] = ($state['turn'] + 1) % count($state['players']);
        }
        $state['version']++;
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE));
        flock($fp, LOCK_UN); fclose($fp);
        echo json_encode(['ok' => true]);
        exit;
    }

    // WYMIANA PŁYTEK
    if ($action === 'swap') {
        $indices = json_decode($_POST['indices'] ?? '[]', true);
        if (count($state['pool']) < 7 || empty($indices)) {
            flock($fp, LOCK_UN); fclose($fp);
            echo json_encode(['ok' => false, 'err' => 'Za mało płytek w worku (min. 7) lub nie wybrano liter.']);
            exit;
        }
        $rack = &$state['players'][$state['turn']]['rack'];
        $returned = [];
        rsort($indices);
        foreach ($indices as $idx) {
            if (isset($rack[$idx])) {
                $returned[] = $rack[$idx];
                array_splice($rack, $idx, 1);
            }
        }
        $drawn = array_splice($state['pool'], 0, count($returned));
        $rack = array_merge($rack, $drawn);
        $state['pool'] = array_merge($state['pool'], $returned);
        shuffle($state['pool']);

        $state['passes'] = 0;
        $state['log'][] = $currentPlayer['nick'] . " wymienił " . count($returned) . " szt.";
        $state['turn'] = ($state['turn'] + 1) % count($state['players']);
        $state['version']++;
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE));
        flock($fp, LOCK_UN); fclose($fp);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ZATWIERDZENIE RUCHU (WYŁOŻENIE SŁOWA)
    if ($action === 'play') {
        $moves = json_decode($_POST['moves'] ?? '[]', true); // [{r, c, rackIdx, char, points}]
        if (empty($moves)) {
            flock($fp, LOCK_UN); fclose($fp);
            echo json_encode(['ok' => false, 'err' => 'Nie ułożono żadnych liter.']);
            exit;
        }

        $board = $state['board'];
        $bonuses = getBoardBonuses();
        $isBoardEmpty = true;
        for ($r = 0; $r < 15; $r++) {
            for ($c = 0; $c < 15; $c++) {
                if ($board[$r][$c] !== null) $isBoardEmpty = false;
            }
        }

        // Walidacja pozycji
        $rows = []; $cols = []; $placedMap = [];
        foreach ($moves as $m) {
            $r = (int)$m['r']; $c = (int)$m['c'];
            if ($r < 0 || $r > 14 || $c < 0 || $c > 14 || $board[$r][$c] !== null || isset($placedMap["$r,$c"])) {
                flock($fp, LOCK_UN); fclose($fp);
                echo json_encode(['ok' => false, 'err' => 'Nieprawidłowe pole na planszy.']);
                exit;
            }
            $rows[] = $r; $cols[] = $c;
            $placedMap["$r,$c"] = ['l' => $m['char'], 'p' => (int)$m['points'], 'new' => true];
        }

        $sameRow = (count(array_unique($rows)) === 1);
        $sameCol = (count(array_unique($cols)) === 1);
        if (!$sameRow && !$sameCol) {
            flock($fp, LOCK_UN); fclose($fp);
            echo json_encode(['ok' => false, 'err' => 'Litery muszą leżeć w jednej linii (poziomo lub pionowo).']);
            exit;
        }

        // Sprawdzenie ciągłości i styczności
        if ($isBoardEmpty) {
            if (!isset($placedMap['7,7'])) {
                flock($fp, LOCK_UN); fclose($fp);
                echo json_encode(['ok' => false, 'err' => 'Pierwsze słowo musi przechodzić przez środek planszy (★).']);
                exit;
            }
            if (count($moves) < 2) {
                flock($fp, LOCK_UN); fclose($fp);
                echo json_encode(['ok' => false, 'err' => 'Pierwsze słowo musi mieć minimum 2 litery.']);
                exit;
            }
        }

        // Tymczasowa plansza
        $tempBoard = $board;
        foreach ($placedMap as $key => $tile) {
            list($r, $c) = explode(',', $key);
            $tempBoard[$r][$c] = $tile;
        }

        // Sprawdzenie dziur w głównym słowie
        if ($sameRow) {
            $r = $rows[0];
            $minC = min($cols); $maxC = max($cols);
            for ($c = $minC; $c <= $maxC; $c++) {
                if ($tempBoard[$r][$c] === null) {
                    flock($fp, LOCK_UN); fclose($fp);
                    echo json_encode(['ok' => false, 'err' => 'Litery nie mogą być oddzielone pustymi polami.']);
                    exit;
                }
            }
        } else {
            $c = $cols[0];
            $minR = min($rows); $maxR = max($rows);
            for ($r = $minR; $r <= $maxR; $r++) {
                if ($tempBoard[$r][$c] === null) {
                    flock($fp, LOCK_UN); fclose($fp);
                    echo json_encode(['ok' => false, 'err' => 'Litery nie mogą być oddzielone pustymi polami.']);
                    exit;
                }
            }
        }

        // Wyszukiwanie wszystkich utworzonych słów i liczenie punktów
        $wordsFound = [];
        $totalPoints = 0;
        $touchesExisting = false;

        // Funkcja zbierająca słowo od (r,c) w kierunku (dr,dc)
        $extractWord = function($startR, $startC, $dr, $dc) use ($tempBoard, $bonuses, &$touchesExisting) {
            $r = $startR; $c = $startC;
            while ($r - $dr >= 0 && $c - $dc >= 0 && $tempBoard[$r - $dr][$c - $dc] !== null) {
                $r -= $dr; $c -= $dc;
            }
            $wordStr = '';
            $wordScore = 0;
            $wordMult = 1;
            $len = 0;
            while ($r < 15 && $c < 15 && $tempBoard[$r][$c] !== null) {
                $tile = $tempBoard[$r][$c];
                $wordStr .= $tile['l'];
                $letterScore = $tile['p'];
                if (!empty($tile['new'])) {
                    $b = $bonuses[$r][$c];
                    if ($b === 'DL') $letterScore *= 2;
                    if ($b === 'TL') $letterScore *= 3;
                    if ($b === 'DW') $wordMult *= 2;
                    if ($b === 'TW') $wordMult *= 3;
                } else {
                    $touchesExisting = true;
                }
                $wordScore += $letterScore;
                $len++;
                $r += $dr; $c += $dc;
            }
            return $len > 1 ? ['word' => $wordStr, 'score' => $wordScore * $wordMult] : null;
        };

        // Główne słowo
        if ($sameRow && count($moves) > 1) {
            $w = $extractWord($rows[0], $cols[0], 0, 1);
            if ($w) $wordsFound[] = $w;
        } elseif ($sameCol && count($moves) > 1) {
            $w = $extractWord($rows[0], $cols[0], 1, 0);
            if ($w) $wordsFound[] = $w;
        } elseif (count($moves) === 1) {
            $wH = $extractWord($rows[0], $cols[0], 0, 1);
            $wV = $extractWord($rows[0], $cols[0], 1, 0);
            if ($wH) $wordsFound[] = $wH;
            if ($wV) $wordsFound[] = $wV;
        }

        // Słowa prostopadłe
        if (count($moves) > 1) {
            foreach ($moves as $m) {
                $w = $sameRow ? $extractWord($m['r'], $m['c'], 1, 0) : $extractWord($m['r'], $m['c'], 0, 1);
                if ($w) $wordsFound[] = $w;
            }
        }

        if (!$isBoardEmpty && !$touchesExisting) {
            flock($fp, LOCK_UN); fclose($fp);
            echo json_encode(['ok' => false, 'err' => 'Nowe słowo musi łączyć się z istniejącymi literami na planszy.']);
            exit;
        }

        if (empty($wordsFound)) {
            flock($fp, LOCK_UN); fclose($fp);
            echo json_encode(['ok' => false, 'err' => 'Nie utworzono żadnego poprawnego słowa (min. 2 litery).']);
            exit;
        }

        foreach ($wordsFound as $wf) {
            $totalPoints += $wf['score'];
        }
        // Premia za wyłożenie wszystkich 7 płytek (Scrabble / Premia 50 pkt)
        if (count($moves) === 7) {
            $totalPoints += 50;
        }

        // Zapisanie na planszy (usunięcie flagi 'new')
        foreach ($placedMap as $key => $tile) {
            list($r, $c) = explode(',', $key);
            $state['board'][$r][$c] = ['l' => $tile['l'], 'p' => $tile['p']];
        }

        // Usunięcie ze stojaka i dobranie nowych
        $rack = &$state['players'][$state['turn']]['rack'];
        $usedIndices = array_column($moves, 'rackIdx');
        rsort($usedIndices);
        foreach ($usedIndices as $idx) {
            array_splice($rack, $idx, 1);
        }
        $needed = 7 - count($rack);
        if ($needed > 0 && count($state['pool']) > 0) {
            $drawn = array_splice($state['pool'], 0, $needed);
            $rack = array_merge($rack, $drawn);
        }

        $state['players'][$state['turn']]['score'] += $totalPoints;
        $state['passes'] = 0;

        $wordsList = implode(', ', array_column($wordsFound, 'word'));
        $bonusText = count($moves) === 7 ? ' (+50 PREMIA!)' : '';
        $state['log'][] = "{$currentPlayer['nick']}: {$wordsList} (+{$totalPoints} pkt{$bonusText})";

        // Koniec gry gdy worek pusty i gracz pozbył się płytek
        if (count($state['pool']) === 0 && count($rack) === 0) {
            $state['log'][] = "Koniec gry! {$currentPlayer['nick']} wyłożył wszystkie litery.";
            finalizeGameState($state);
        } else {
            $state['turn'] = ($state['turn'] + 1) % count($state['players']);
        }

        $state['version']++;
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE));
        flock($fp, LOCK_UN); fclose($fp);
        echo json_encode(['ok' => true]);
        exit;
    }
}

$roomCode = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['room'] ?? '');
$bonusesJson = json_encode(getBoardBonuses());
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Literaki</title>
<style>
    :root {
        --bg: #1e242b; --panel: #28303a; --text: #f0f4f8;
        --tile: #f3d59b; --tile-border: #c59b52; --tile-text: #1a1a1a;
        --tw: #e74c3c; --dw: #f39c12; --tl: #2980b9; --dl: #3498db;
    }
    * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, sans-serif; }
    body { margin: 0; background: var(--bg); color: var(--text); display: flex; flex-direction: column; align-items: center; min-height: 100vh; }
    h1 { margin: 12px 0; font-size: 1.5rem; letter-spacing: 1px; }
    
    /* Lobby */
    .card { background: var(--panel); padding: 25px; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.4); max-width: 420px; width: 92%; margin-top: 30px; text-align: center; }
    input, select, button { width: 100%; padding: 12px; margin: 8px 0; border-radius: 6px; border: none; font-size: 1rem; }
    input, select { background: #181d23; color: #fff; border: 1px solid #3f4b59; }
    button { background: #27ae60; color: white; font-weight: bold; cursor: pointer; transition: 0.2s; }
    button:hover { background: #2ecc71; }
    button.sec { background: #34495e; }
    button.sec:hover { background: #46627f; }
    button.warn { background: #d35400; }
    button:disabled { background: #555; cursor: not-allowed; opacity: 0.6; }

    /* Gra */
    #game-container { display: none; width: 100%; max-width: 1100px; padding: 10px; gap: 16px; flex-wrap: wrap; justify-content: center; }
    .board-wrap { background: #143d29; padding: 8px; border-radius: 8px; box-shadow: 0 6px 20px rgba(0,0,0,0.5); }
    .board { display: grid; grid-template-columns: repeat(15, 34px); grid-template-rows: repeat(15, 34px); gap: 2px; background: #0d2b1d; }
    .cell { width: 34px; height: 34px; background: #1f5c3e; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: bold; color: rgba(255,255,255,0.7); cursor: pointer; position: relative; user-select: none; border-radius: 3px; }
    .cell.TW { background: var(--tw); color: #fff; }
    .cell.DW { background: var(--dw); color: #fff; }
    .cell.TL { background: var(--tl); color: #fff; }
    .cell.DL { background: var(--dl); color: #fff; }
    .cell.center { font-size: 16px; }

    /* Płytki */
    .tile { width: 32px; height: 32px; background: var(--tile); color: var(--tile-text); border: 2px solid var(--tile-border); border-radius: 4px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 17px; position: relative; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.4); user-select: none; }
    .tile.tentative { background: #a8e6cf; border-color: #3b9a72; }
    .tile.selected { transform: translateY(-6px); border-color: #ff4757; box-shadow: 0 4px 10px rgba(255,71,87,0.6); }
    .tile .pts { position: absolute; bottom: 1px; right: 2px; font-size: 9px; font-weight: bold; }

    /* Panel boczny */
    .sidebar { flex: 1; min-width: 300px; max-width: 400px; display: flex; flex-direction: column; gap: 12px; }
    .box { background: var(--panel); padding: 14px; border-radius: 8px; }
    .player-row { display: flex; justify-content: space-between; padding: 8px 10px; border-radius: 5px; margin-bottom: 4px; background: #1e242b; }
    .player-row.active { border-left: 4px solid #2ecc71; background: #23382c; font-weight: bold; }
    
    /* Stojak i akcje */
    .rack-area { display: flex; flex-direction: column; align-items: center; gap: 10px; margin-top: 10px; }
    .rack { display: flex; gap: 6px; background: #3d2817; padding: 10px 14px; border-radius: 8px; min-height: 56px; border-bottom: 5px solid #26180d; }
    .rack .tile { width: 42px; height: 42px; font-size: 21px; }
    .rack .tile .pts { font-size: 11px; }
    .actions { display: flex; gap: 6px; width: 100%; }
    .actions button { margin: 0; padding: 10px 6px; font-size: 0.9rem; }

    .log { font-size: 0.85rem; color: #bdc3c7; max-height: 150px; overflow-y: auto; line-height: 1.4; }
    .log div { border-bottom: 1px solid #343f4b; padding: 3px 0; }
    .share-link { background: #181d23; padding: 8px; border-radius: 4px; font-size: 0.85rem; word-break: break-all; border: 1px dashed #555; margin-top: 6px; cursor: pointer; }

    @media (max-width: 600px) {
        .board { grid-template-columns: repeat(15, 22px); grid-template-rows: repeat(15, 22px); gap: 1px; }
        .cell { width: 22px; height: 22px; font-size: 7px; }
        .tile { width: 21px; height: 21px; font-size: 12px; border-width: 1px; }
        .tile .pts { font-size: 6px; }
    }
</style>
</head>
<body>

<!-- LOBBY -->
<div id="lobby" class="card">
    <div id="create-section">
        <h3>Nowa gra</h3>
        <input type="text" id="create-nick" placeholder="Twój nick" maxlength="15">
        <select id="create-max">
            <option value="3">3 Graczy</option>
            <option value="4" selected>4 Graczy</option>
            <option value="2">2 Graczy</option>
        </select>
        <button onclick="createRoom()">Utwórz pokój</button>
    </div>

    <div id="join-section" style="display:none;">
        <h3>Dołącz do pokoju: <span id="lobby-room-code" style="color:#2ecc71"></span></h3>
        <input type="text" id="join-nick" placeholder="Twój nick" maxlength="15">
        <button onclick="joinRoom()">Wejdź do gry</button>
    </div>
</div>

<!-- GRA -->
<div id="game-container">
    <div>
        <div class="board-wrap">
            <div class="board" id="board"></div>
        </div>
        <div class="rack-area">
            <div class="rack" id="rack"></div>
            <div class="actions">
                <button id="btn-play" onclick="submitPlay()">✔ Zatwierdź</button>
                <button id="btn-clear" class="sec" onclick="recallTiles()">↩ Cofnij</button>
                <button id="btn-swap" class="warn" onclick="swapTiles()">⇄ Wymień</button>
                <button id="btn-pass" class="sec" onclick="passTurn()">⏭ Pas</button>
            </div>
        </div>
    </div>

    <div class="sidebar">
        <div class="box">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <strong>Pokój: <span id="ui-room" style="color:#2ecc71"></span></strong>
                <span>Worek: <strong id="ui-pool">0</strong> szt.</span>
            </div>
            <div class="share-link" onclick="copyLink()" title="Kliknij, aby skopiować">
                🔗 Kliknij, aby skopiować link dla znajomych
            </div>
            <div id="waiting-controls" style="display:none; margin-top:10px;">
                <p style="font-size:0.9rem; color:#f39c12; margin:4px 0;">Oczekiwanie na graczy...</p>
                <button id="btn-force-start" class="sec" onclick="forceStart()" style="display:none;">Rozpocznij teraz</button>
            </div>
        </div>

        <div class="box">
            <strong>Wyniki:</strong>
            <div id="players-list" style="margin-top:8px;"></div>
        </div>

        <div class="box">
            <strong>Przebieg gry:</strong>
            <div class="log" id="game-log" style="margin-top:6px;"></div>
        </div>
    </div>
</div>

<div style="margin-top:20px;">
Mordamorda
</div>

<script>
const BONUSES = <?= $bonusesJson ?>;
const URL_ROOM = "<?= $roomCode ?>";
let state = null;
let lastVersion = -1;
let selectedRackIdx = null;
let tentativeMoves = []; // [{r, c, rackIdx, char, points}]

// Inicjalizacja
if (URL_ROOM) {
    document.getElementById('create-section').style.display = 'none';
    document.getElementById('join-section').style.display = 'block';
    document.getElementById('lobby-room-code').innerText = URL_ROOM;
    checkInitialState();
}

async function api(endpoint, data = {}) {
    const form = new FormData();
    for (let k in data) form.append(k, data[k]);
    const res = await fetch(`?api=${endpoint}&room=${URL_ROOM || (state ? state.room : '')}`, {
        method: 'POST', body: form
    });
    return await res.json();
}

async function checkInitialState() {
    const res = await api('state');
    if (res.ok && res.myIndex >= 0) {
        enterGame();
    } else if (!res.ok) {
        alert(res.err);
        window.location.href = window.location.pathname;
    }
}

async function createRoom() {
    const nick = document.getElementById('create-nick').value.trim() || 'Gracz 1';
    const max = document.getElementById('create-max').value;
    const res = await api('create', { nick, max });
    if (res.ok) {
        window.location.href = `?room=${res.room}`;
    }
}

async function joinRoom() {
    const nick = document.getElementById('join-nick').value.trim() || 'Gracz';
    const res = await api('join', { nick });
    if (res.ok) enterGame();
}

function enterGame() {
    document.getElementById('lobby').style.display = 'none';
    document.getElementById('game-container').style.display = 'flex';
    buildEmptyBoard();
    fetchState();
    setInterval(fetchState, 2000);
}

function buildEmptyBoard() {
    const boardEl = document.getElementById('board');
    boardEl.innerHTML = '';
    const labels = { 'TW': '3xS', 'DW': '2xS', 'TL': '3xL', 'DL': '2xL' };
    for (let r = 0; r < 15; r++) {
        for (let c = 0; c < 15; c++) {
            const cell = document.createElement('div');
            const b = BONUSES[r][c];
            cell.className = 'cell ' + b + (r === 7 && c === 7 ? ' center' : '');
            cell.id = `c-${r}-${c}`;
            cell.innerText = (r === 7 && c === 7) ? '★' : (labels[b] || '');
            cell.onclick = () => onCellClick(r, c);
            boardEl.appendChild(cell);
        }
    }
}

async function fetchState() {
    const res = await api('state');
    if (!res.ok) return;
    if (res.version === lastVersion) return;
    lastVersion = res.version;
    state = res;
    tentativeMoves = [];
    selectedRackIdx = null;
    renderAll();
}

function renderAll() {
    if (!state) return;
    document.getElementById('ui-room').innerText = state.room;
    document.getElementById('ui-pool').innerText = state.poolCount;

    // Status oczekiwania
    const waitBox = document.getElementById('waiting-controls');
    if (state.status === 'waiting') {
        waitBox.style.display = 'block';
        if (state.myIndex === 0 && state.players.length >= 2) {
            document.getElementById('btn-force-start').style.display = 'block';
        }
    } else {
        waitBox.style.display = 'none';
    }

    // Lista graczy
    const pList = document.getElementById('players-list');
    pList.innerHTML = '';
    state.players.forEach((p, idx) => {
        const div = document.createElement('div');
        div.className = 'player-row' + (state.status === 'playing' && state.turn === idx ? ' active' : '');
        div.innerHTML = `<span>${p.nick} ${p.isMe ? '(Ty)' : ''}</span><span>${p.score} pkt</span>`;
        pList.appendChild(div);
    });

    // Log
    const logEl = document.getElementById('game-log');
    logEl.innerHTML = state.log.map(l => `<div>${l}</div>`).join('');
    logEl.scrollTop = logEl.scrollHeight;

    // Przyciski
    const isMyTurn = (state.status === 'playing' && state.turn === state.myIndex);
    document.getElementById('btn-play').disabled = !isMyTurn;
    document.getElementById('btn-swap').disabled = !isMyTurn;
    document.getElementById('btn-pass').disabled = !isMyTurn;

    renderBoard();
    renderRack();
}

function renderBoard() {
    const labels = { 'TW': '3xS', 'DW': '2xS', 'TL': '3xL', 'DL': '2xL' };
    for (let r = 0; r < 15; r++) {
        for (let c = 0; c < 15; c++) {
            const cell = document.getElementById(`c-${r}-${c}`);
            const fixed = state.board[r][c];
            const temp = tentativeMoves.find(m => m.r === r && m.c === c);

            if (fixed) {
                cell.innerHTML = `<div class="tile">${fixed.l}<span class="pts">${fixed.p}</span></div>`;
            } else if (temp) {
                cell.innerHTML = `<div class="tile tentative">${temp.char}<span class="pts">${temp.points}</span></div>`;
            } else {
                const b = BONUSES[r][c];
                cell.innerHTML = (r === 7 && c === 7) ? '★' : (labels[b] || '');
            }
        }
    }
}

function renderRack() {
    const rackEl = document.getElementById('rack');
    rackEl.innerHTML = '';
    if (!state.myRack) return;

    state.myRack.forEach((tile, idx) => {
        const isPlaced = tentativeMoves.some(m => m.rackIdx === idx);
        if (isPlaced) return;

        const div = document.createElement('div');
        div.className = 'tile' + (selectedRackIdx === idx ? ' selected' : '');
        div.innerHTML = `${tile.l === '_' ? '?' : tile.l}<span class="pts">${tile.p}</span>`;
        div.onclick = () => {
            selectedRackIdx = (selectedRackIdx === idx) ? null : idx;
            renderRack();
        };
        rackEl.appendChild(div);
    });
}

function onCellClick(r, c) {
    if (state.status !== 'playing' || state.turn !== state.myIndex) return;
    if (state.board[r][c] !== null) return;

    // Jeśli kliknięto tymczasową płytkę - zdejmij ją
    const existingIdx = tentativeMoves.findIndex(m => m.r === r && m.c === c);
    if (existingIdx >= 0) {
        tentativeMoves.splice(existingIdx, 1);
        renderBoard();
        renderRack();
        return;
    }

    // Jeśli wybrano płytkę ze stojaka - połóż na planszy
    if (selectedRackIdx !== null) {
        const tile = state.myRack[selectedRackIdx];
        let char = tile.l;
        if (char === '_') {
            const input = prompt("Blank (mydło)! Wpisz polską literę (np. A, Ą, Ć, Ż):");
            if (!input) return;
            char = input.trim().toUpperCase().charAt(0);
            if (!/^[A-ZĄĆĘŁŃÓŚŹŻ]$/.test(char)) {
                alert("Nieprawidłowa litera!");
                return;
            }
        }
        tentativeMoves.push({ r, c, rackIdx: selectedRackIdx, char, points: tile.p });
        selectedRackIdx = null;
        renderBoard();
        renderRack();
    }
}

function recallTiles() {
    tentativeMoves = [];
    selectedRackIdx = null;
    renderBoard();
    renderRack();
}

async function submitPlay() {
    if (tentativeMoves.length === 0) return alert("Najpierw ułóż litery na planszy!");
    const res = await api('play', { moves: JSON.stringify(tentativeMoves) });
    if (!res.ok) alert(res.err);
    else fetchState();
}

async function passTurn() {
    if (!confirm("Czy na pewno chcesz spasować?")) return;
    const res = await api('pass');
    if (!res.ok) alert(res.err);
    else fetchState();
}

async function swapTiles() {
    recallTiles();
    const input = prompt("Podaj numery płytek ze stojaka do wymiany (od 1 do 7, np. 1,3,5):");
    if (!input) return;
    const indices = input.split(',')
        .map(s => parseInt(s.trim()) - 1)
        .filter(n => !isNaN(n) && n >= 0 && n < state.myRack.length);
    const unique = [...new Set(indices)];
    if (unique.length === 0) return;

    const res = await api('swap', { indices: JSON.stringify(unique) });
    if (!res.ok) alert(res.err);
    else fetchState();
}

async function forceStart() {
    const res = await api('force_start');
    if (res.ok) fetchState();
}

function copyLink() {
    navigator.clipboard.writeText(window.location.href);
    alert("Skopiowano link do pokoju! Wyślij go znajomym.");
}
</script>
</body>
</html>
