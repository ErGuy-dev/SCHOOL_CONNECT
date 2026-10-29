<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/document_security.php';
doc_method('GET'); doc_need('documents.view'); $uid=doc_user(); $role=$_SESSION['role']??'';
$q=trim((string)($_GET['q']??'')); $cat=(string)($_GET['categorie']??'');
$sql='SELECT d.id,d.titre,d.description,d.categorie,d.version_courante,d.est_archive,d.cree_le,d.modifie_le,u.nom createur,v.nom_original,v.taille_octets FROM documents d JOIN users u ON u.id=d.cree_par LEFT JOIN document_versions v ON v.document_id=d.id AND v.numero_version=d.version_courante WHERE d.supprime_le IS NULL';
$p=[];
if(!(doc_manager() && ($_GET['archives']??'')==='1')) $sql.=' AND d.est_archive=0';
if($q!=='') {$sql.=' AND (d.titre LIKE ? OR d.description LIKE ? OR v.nom_original LIKE ?)';$like='%'.$q.'%';array_push($p,$like,$like,$like);}
if(in_array($cat,['administratif','pedagogique'],true)){$sql.=' AND d.categorie=?';$p[]=$cat;}
if(!doc_manager()){
 if($role==='enseignant'){$sql.=' AND (d.cree_par=? OR EXISTS(SELECT 1 FROM document_shares s WHERE s.document_id=d.id AND s.user_id=? AND (s.expire_le IS NULL OR s.expire_le>NOW())))';array_push($p,$uid,$uid);}
 else {$sql.=' AND EXISTS(SELECT 1 FROM document_shares s WHERE s.document_id=d.id AND s.user_id=? AND (s.expire_le IS NULL OR s.expire_le>NOW()))';$p[]=$uid;}
}
$sql.=' ORDER BY d.modifie_le DESC,d.id DESC';$s=db()->prepare($sql);$s->execute($p);$docs=$s->fetchAll();
foreach($docs as &$d){$d['can_download']=doc_can_download((int)$d['id']);$d['can_add_version']=doc_can_version((int)$d['id']);$d['can_share']=doc_manager()&&doc_has('documents.share');}unset($d);
doc_json(200,['success'=>true,'documents'=>$docs]);
