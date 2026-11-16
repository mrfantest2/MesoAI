<?php
declare(strict_types=1);

// CP031: isolated v1/v2 SQLite compatibility, foreign keys and three-account
// constraints. Creates only throwaway databases in the operating-system temp.
require dirname(__DIR__).'/web/includes/chat_auth.php';
require dirname(__DIR__).'/web/includes/memory.php';

function cp031_assert(bool $value, string $label): void {
    if (!$value) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
}
function cp031_db(string $path): PDO {
    $db = new PDO('sqlite:'.$path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys=ON');
    return $db;
}
function cp031_fixture(string $path, int $version, bool $wide = false): void {
    $db=cp031_db($path);
    $audience=$version===1 ? '' :
        ", audience TEXT NULL CHECK(audience IN ('majid','mira'".($wide?",'jamal'":'')."))";
    $db->exec("CREATE TABLE conversations (
        id TEXT PRIMARY KEY, title TEXT NOT NULL, created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL, archived_at INTEGER NULL,
        deleted_at INTEGER NULL{$audience})");
    $db->exec("CREATE TABLE messages (
        seq INTEGER PRIMARY KEY AUTOINCREMENT, id TEXT NOT NULL UNIQUE,
        conversation_id TEXT NOT NULL, role TEXT NOT NULL,
        content TEXT NOT NULL, created_at INTEGER NOT NULL,
        FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE)");
    $db->exec("CREATE TABLE memory_items (
        id TEXT PRIMARY KEY, conversation_id TEXT NOT NULL,
        message_id TEXT NULL, text TEXT NOT NULL,
        FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
        FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE SET NULL)");
    $db->exec('CREATE INDEX idx_cp031_title ON conversations(title)');
    $id=str_repeat('a',64); $msg=str_repeat('b',64); $item=str_repeat('c',64);
    $cols='id,title,created_at,updated_at,archived_at,deleted_at';
    $vals="'{$id}','legacy-preserved',123,456,NULL,NULL";
    if ($version===2) {$cols.=',audience';$vals.=",'majid'";}
    $db->exec("INSERT INTO conversations ({$cols}) VALUES ({$vals})");
    $db->exec("INSERT INTO messages(id,conversation_id,role,content,created_at)
        VALUES('{$msg}','{$id}','user','synthetic-original',789)");
    $db->exec("INSERT INTO memory_items(id,conversation_id,message_id,text)
        VALUES('{$item}','{$id}','{$msg}','synthetic-memory')");
    $db->exec('PRAGMA user_version='.$version);
    $db=null;
}
function cp031_verify(string $path, ?string $expectedAudience): void {
    $db=meso_memory_connect($path);
    cp031_assert(meso_memory_schema_version($db)===3,'schema upgraded to v3');
    cp031_assert($db->query('PRAGMA quick_check')->fetchColumn()==='ok','SQLite quick_check');
    cp031_assert($db->query('PRAGMA foreign_key_check')->fetchAll()===[],'foreign keys valid');
    cp031_assert((int)$db->query('PRAGMA foreign_keys')->fetchColumn()===1,'foreign keys remain enabled');
    $row=$db->query('SELECT id,title,created_at,updated_at,archived_at,deleted_at,audience FROM conversations')->fetch(PDO::FETCH_ASSOC);
    cp031_assert($row!==false && $row['id']===str_repeat('a',64)
        && $row['title']==='legacy-preserved'
        && (int)$row['created_at']===123 && (int)$row['updated_at']===456
        && $row['archived_at']===null && $row['deleted_at']===null
        && $row['audience']===$expectedAudience,'legacy conversation exact identity/ownership');
    cp031_assert((int)$db->query('SELECT count(*) FROM messages')->fetchColumn()===1
        && (int)$db->query('SELECT count(*) FROM memory_items')->fetchColumn()===1,
        'children preserved');
    cp031_assert($db->query("SELECT content FROM messages LIMIT 1")->fetchColumn()==='synthetic-original',
        'message content preserved');
    cp031_assert($db->query("SELECT text FROM memory_items LIMIT 1")->fetchColumn()==='synthetic-memory',
        'memory content preserved');
    cp031_assert($db->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_cp031_title'")->fetchColumn()==='idx_cp031_title',
        'custom index retained');
    $stmt=$db->prepare('INSERT INTO conversations(id,title,created_at,updated_at,audience) VALUES(?,?,?,?,?)');
    foreach (['majid','mira','jamal'] as $role) {
        $stmt->execute([hash('sha256','synthetic-'.$role),$role,800,801,$role]);
    }
    cp031_assert((int)$db->query("SELECT count(*) FROM conversations WHERE audience='jamal'")->fetchColumn()===1,'Jamal permitted');
    $rejected=false;
    try {$stmt->execute([str_repeat('f',64),'invalid',800,801,'another']);}
    catch(PDOException $e){$rejected=true;}
    cp031_assert($rejected,'unknown role rejected by SQLite');
    cp031_assert(meso_memory_schema_version(meso_memory_connect($path))===3,'repeat open idempotent');
    $db=null;
}
function cp031_delete_tree(string $root): void {
    if (!is_dir($root)) return;
    $it=new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());
    @rmdir($root);
}

$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'meso-cp031-'.bin2hex(random_bytes(6));
if (!mkdir($root,0700,true)) throw new RuntimeException('temporary_directory_unavailable');
try {
    foreach ([
        ['v1',1,false,null],
        ['v2-old',2,false,'majid'],
        ['v2-wide',2,true,'majid']
    ] as [$label,$version,$wide,$expected]) {
        echo 'CASE '.$label.PHP_EOL;
        $path=$root.DIRECTORY_SEPARATOR.$label.'.sqlite';
        cp031_fixture($path,$version,$wide);
        cp031_verify($path,$expected);
    }
    echo 'CASE broken foreign key / rollback'.PHP_EOL;
    $path=$root.DIRECTORY_SEPARATOR.'bad-fk.sqlite';
    cp031_fixture($path,2,false);
    $db=cp031_db($path);
    $db->exec('PRAGMA foreign_keys=OFF');
    $db->exec("UPDATE messages SET conversation_id='".str_repeat('e',64)."'");
    $db->exec('PRAGMA foreign_keys=ON');
    $db=null;
    $failed=false;
    try {meso_memory_connect($path);}
    catch(Throwable $e){$failed=true;}
    cp031_assert($failed,'invalid foreign key migration blocked');
    $db=cp031_db($path);
    cp031_assert(meso_memory_schema_version($db)===2,'failed migration rolled back');
    cp031_assert(str_contains((string)$db->query("SELECT sql FROM sqlite_master WHERE name='conversations'")->fetchColumn(),"('majid','mira')"),
        'original v2 CHECK retained on failure');
    $db=null;

    echo 'CASE fresh v3'.PHP_EOL;
    $path=$root.DIRECTORY_SEPARATOR.'fresh.sqlite';
    $db=meso_memory_connect($path);
    cp031_assert(meso_memory_schema_version($db)===3,'fresh v3 schema');
    $db->exec("INSERT INTO conversations(id,title,created_at,updated_at,audience)
        VALUES('".str_repeat('d',64)."','Jamal',1,1,'jamal')");
    cp031_assert((int)$db->query("SELECT count(*) FROM conversations WHERE audience='jamal'")->fetchColumn()===1,
        'fresh v3 accepts Jamal');
    $db=null;
    echo "CP031_THREE_ACCOUNT_SCHEMA=PASS".PHP_EOL;
} finally {
    cp031_delete_tree($root);
}
