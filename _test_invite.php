<?php
$config = require __DIR__ . '/config.php';
function b64t($v){return rtrim(strtr(base64_encode($v),'+/','-_'),'=');}
function reqt($action,$body,$token='',$email=''){
    $ch=curl_init('http://localhost/corrida-mpl-php-js-html-css/api.php?action='.rawurlencode($action));
    $headers=array('Content-Type: application/json'); if($token!==''){$headers[]='Authorization: Bearer '.$token;$headers[]='X-MPL-Token: '.$token;} if($email!=='')$headers[]='X-MPL-Email: '.$email;
    curl_setopt_array($ch,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_TIMEOUT=>20));
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return array($status,json_decode($raw,true));
}
$h=b64t(json_encode(array('alg'=>'HS256','typ'=>'JWT')));$p=b64t(json_encode(array('sub'=>1,'role'=>'SUPER_ADMIN','email'=>'test@local','iat'=>time(),'exp'=>time()+600)));$token=$h.'.'.$p.'.'.b64t(hash_hmac('sha256',$h.'.'.$p,$config['jwt_secret'],true));
list($s,$options)=reqt('admin-api',array('action'=>'invitation-options'),$token);if($s!==200||empty($options))die("FAIL options ".$s.' '.json_encode($options)."\n");$o=$options[0];
list($s,$created)=reqt('admin-api',array('action'=>'create-invitation','event_id'=>$o['event_id'],'category_id'=>$o['category_id']),$token);if($s!==201||empty($created['link']))die("FAIL create invite\n");
parse_str(parse_url($created['link'],PHP_URL_QUERY),$query);$invite=$query['convite'];
list($s,$info)=reqt('invitation-info',array('token'=>$invite));if($s!==200||$info['type']!=='COLABORADOR_MPL')die("FAIL invite info\n");
$suffix=substr((string)time(),-8);$email='invite-test-'.$suffix.'@example.test';$cpf='900'.str_pad($suffix,8,'0',STR_PAD_LEFT);
$data=array('invitation_token'=>$invite,'category_id'=>$o['category_id'],'name'=>'Teste Convite MPL','cpf'=>$cpf,'birth_date'=>'1990-01-01','gender'=>'OUTRO','email'=>$email,'phone'=>'62999999999','shirt_size'=>'M','zip_code'=>'74000000','street'=>'Rua Teste','address_number'=>'1','complement'=>'','neighborhood'=>'Centro','city'=>'Goiania','state'=>'GO','emergency_name'=>'Contato Teste','emergency_phone'=>'62988888888','terms'=>'on');
list($s,$registration)=reqt('create-registration',$data);if($s!==200||empty($registration['is_collaborator']))die('FAIL registration '.json_encode($registration)."\n");
list($reuseStatus,$reuse)=reqt('create-registration',$data);
$db=new PDO('mysql:host='.$config['db_host'].';port='.$config['db_port'].';dbname='.$config['db_database'].';charset=utf8mb4',$config['db_user'],$config['db_password'],array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
$q=$db->prepare("SELECT i.tipo,i.valor,i.status,(SELECT COUNT(*) FROM tickets t WHERE t.inscricao_id=i.id) tickets,(SELECT COUNT(*) FROM pagamentos_gateway pg WHERE pg.inscricao_id=i.id) gateway,(SELECT status FROM pagamentos p WHERE p.inscricao_id=i.id LIMIT 1) payment_status FROM inscricoes i WHERE i.id=?");$q->execute(array($registration['id']));$check=$q->fetch(PDO::FETCH_ASSOC);
if($reuseStatus!==410||$check['tipo']!=='colaborador_mpl'||(float)$check['valor']!==0.0||$check['status']!=='confirmada'||(int)$check['tickets']!==1||(int)$check['gateway']!==0||$check['payment_status']!=='isento')die('FAIL assertions '.json_encode(array($reuseStatus,$check))."\n");
echo "PASS invite_single_use ticket_isento no_gateway\n";
$db->beginTransaction();$db->prepare('DELETE FROM retiradas_kit WHERE inscricao_id=?')->execute(array($registration['id']));$db->prepare('DELETE FROM tickets WHERE inscricao_id=?')->execute(array($registration['id']));$db->prepare('DELETE FROM pagamentos WHERE inscricao_id=?')->execute(array($registration['id']));$db->prepare('DELETE FROM contatos_emergencia WHERE participante_id=(SELECT participante_id FROM inscricoes WHERE id=?)')->execute(array($registration['id']));$db->prepare('DELETE FROM enderecos WHERE participante_id=(SELECT participante_id FROM inscricoes WHERE id=?)')->execute(array($registration['id']));$q=$db->prepare('SELECT participante_id FROM inscricoes WHERE id=?');$q->execute(array($registration['id']));$pid=$q->fetchColumn();$db->prepare('DELETE FROM convites_colaboradores WHERE inscricao_id=?')->execute(array($registration['id']));$db->prepare('DELETE FROM inscricoes WHERE id=?')->execute(array($registration['id']));$db->prepare('DELETE FROM participantes WHERE id=?')->execute(array($pid));$db->commit();
