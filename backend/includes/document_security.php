<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';

const DOCUMENT_STORAGE_DIR = 'C:\\xampp\\schoolconnect-storage\\documents';
const DOCUMENT_MAX_BYTES = 20971520;

function doc_json(int $status, array $data): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function doc_method(string $method): void {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: '.$method);
        doc_json(405,['success'=>false,'message'=>'Méthode HTTP non autorisée.']);
    }
}
function doc_user(): int {
    $id = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) doc_json(401,['success'=>false,'message'=>'Connexion requise.']);
    $s=db()->prepare('SELECT u.nom,u.statut,r.code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?');
    $s->execute([$id]); $u=$s->fetch();
    if (!$u || $u['statut']!=='actif') {
        $_SESSION=[]; session_destroy();
        doc_json(401,['success'=>false,'message'=>'Compte inactif ou session expirée.']);
    }
    $_SESSION['user_nom']=$u['nom']; $_SESSION['role']=$u['code'];
    return (int)$id;
}
function doc_has(string $permission): bool {
    $s=db()->prepare('SELECT COUNT(*) FROM users u JOIN role_permissions rp ON rp.role_id=u.role_id JOIN permissions p ON p.id=rp.permission_id WHERE u.id=? AND u.statut="actif" AND p.code=?');
    $s->execute([doc_user(),$permission]); return (int)$s->fetchColumn()>0;
}
function doc_need(string $permission): int {
    $id=doc_user();
    if (!doc_has($permission)) doc_json(403,['success'=>false,'message'=>'Permission insuffisante.']);
    return $id;
}
function doc_csrf(): void {
    $sent=$_POST['csrf']??''; $token=$_SESSION['csrf']??'';
    if (!is_string($sent)||!is_string($token)||$token===''||!hash_equals($token,$sent))
        doc_json(403,['success'=>false,'message'=>'Jeton CSRF invalide. Rechargez la page.']);
}
function doc_manager(): bool { return in_array($_SESSION['role']??'', ['administrateur','direction'], true); }
function doc_can_view(int $id): bool {
    $uid=doc_user(); if(doc_manager()) return true;
    $s=db()->prepare('SELECT cree_par FROM documents WHERE id=? AND supprime_le IS NULL');
    $s->execute([$id]); $owner=$s->fetchColumn();
    if($owner===false) return false;
    if(($_SESSION['role']??'')==='enseignant' && (int)$owner===$uid) return true;
    $s=db()->prepare('SELECT COUNT(*) FROM document_shares WHERE document_id=? AND user_id=? AND (expire_le IS NULL OR expire_le>NOW())');
    $s->execute([$id,$uid]); return (int)$s->fetchColumn()>0;
}
function doc_can_download(int $id): bool {
    $uid=doc_user(); if(doc_manager()) return true;
    $s=db()->prepare('SELECT cree_par,est_archive FROM documents WHERE id=? AND supprime_le IS NULL');
    $s->execute([$id]); $d=$s->fetch();
    if(!$d || (int)$d['est_archive']===1) return false;
    if(($_SESSION['role']??'')==='enseignant' && (int)$d['cree_par']===$uid) return true;
    $s=db()->prepare('SELECT COUNT(*) FROM document_shares WHERE document_id=? AND user_id=? AND permission IN ("telechargement","modification") AND (expire_le IS NULL OR expire_le>NOW())');
    $s->execute([$id,$uid]); return (int)$s->fetchColumn()>0;
}
function doc_can_version(int $id): bool {
    $uid=doc_user(); if(doc_manager()) return true;
    if(($_SESSION['role']??'')!=='enseignant' || !doc_has('documents.versions')) return false;
    $s=db()->prepare('SELECT COUNT(*) FROM documents WHERE id=? AND cree_par=? AND est_archive=0 AND supprime_le IS NULL');
    $s->execute([$id,$uid]); return (int)$s->fetchColumn()>0;
}
function doc_upload(array $f): array {
    if(!isset($f['error']) || (int)$f['error']!==UPLOAD_ERR_OK) throw new RuntimeException('Échec du téléversement. Vérifiez la taille et php.ini.');
    if(!is_uploaded_file((string)$f['tmp_name'])) throw new RuntimeException('Téléversement invalide.');
    $size=(int)$f['size']; if($size<1||$size>DOCUMENT_MAX_BYTES) throw new RuntimeException('Le fichier doit peser au maximum 20 Mio.');
    $original=basename(str_replace('\\','/',(string)$f['name']));
    $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
    $types=[
      'pdf'=>['application/pdf'],'txt'=>['text/plain'],'csv'=>['text/plain','text/csv','application/csv','application/vnd.ms-excel'],
      'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],
      'doc'=>['application/msword','application/CDFV2','application/octet-stream'],
      'docx'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip'],
      'xls'=>['application/vnd.ms-excel','application/CDFV2','application/octet-stream'],
      'xlsx'=>['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip'],
      'ppt'=>['application/vnd.ms-powerpoint','application/CDFV2','application/octet-stream'],
      'pptx'=>['application/vnd.openxmlformats-officedocument.presentationml.presentation','application/zip']
    ];
    if(!isset($types[$ext])) throw new RuntimeException('Format non autorisé : PDF, TXT, CSV, JPG, PNG, DOC/DOCX, XLS/XLSX, PPT/PPTX.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$f['tmp_name'])?:'application/octet-stream';
    if(!in_array($mime,$types[$ext],true)) throw new RuntimeException('Le contenu ne correspond pas à l’extension.');
    if(!is_dir(DOCUMENT_STORAGE_DIR) && !mkdir(DOCUMENT_STORAGE_DIR,0700,true) && !is_dir(DOCUMENT_STORAGE_DIR)) throw new RuntimeException('Impossible de créer le stockage privé.');
    if(!is_writable(DOCUMENT_STORAGE_DIR)) throw new RuntimeException('Apache ne peut pas écrire dans le stockage privé.');
    $name=bin2hex(random_bytes(24)).'.'.$ext;
    $path=rtrim(DOCUMENT_STORAGE_DIR,'\\/').DIRECTORY_SEPARATOR.$name;
    if(!move_uploaded_file((string)$f['tmp_name'],$path)) throw new RuntimeException('Impossible de déplacer le fichier.');
    @chmod($path,0600);
    return ['original'=>mb_substr($original,0,255),'ext'=>$ext,'mime'=>$mime,'size'=>$size,'sha'=>hash_file('sha256',$path),'name'=>$name,'path'=>$path];
}
function doc_active(int $id): ?array {
    $s=db()->prepare('SELECT * FROM documents WHERE id=? AND supprime_le IS NULL'); $s->execute([$id]); return $s->fetch() ?: null;
}
