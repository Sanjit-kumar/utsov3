<?php

namespace UtsovAPI;
use PDO;

//require(dirname(__FILE__).'/utils.php');

//// Main Section /////
    //$_post = json_decode(file_get_contents("php://input"));


    //Function to return  list of patrons

    function getPatronList(){
        $return["err"] = '';
        $return["msg"] = '';
        $arr = array();
        try {
            /*** connect to SQLite database ***/
            $db = new PDO("sqlite:" . getDBPath("patron"));

            $result = $db->query('SELECT * FROM tb_patrons');
            $num = 0;
            foreach($result as $row)
            {
                $arr[$num] = $row;
                $num++;
            }

            $return["data"] = $arr;
            $return ["msg"] = $num . " rows returned";
            //closing DB
            $db = NULL;
        }
        catch(PDOException $e)
        {
            $return["err"] = "DB: Unhandled PDO Exception";
            $return["msg"] = $e->getMessage();
        }

        return $return;
        //echo json_encode($return);
    }

    //Function to find Patron by id
    function getPatron($patronid){
        $return["err"] = '';
        $return["msg"] = '';
        $arr = array();
        
        try {
            /*** connect to SQLite database ***/
            $db = new PDO("sqlite:" . getDBPath("patron"));
            
            $stmt = $db->prepare('SELECT * FROM tb_patrons WHERE id = :patronid');
            
            $bindVar = $stmt->bindParam(':patronid', $patronid);
            
            if($bindVar)
            {
                $num = 0;
                $stmt->execute();
                $result = $stmt->fetchAll();
                foreach($result as $row)
                {
                    $arr[$num] = $row;
                    $num++;
                }
                
                $return["data"] = $arr;
                $return ["msg"] = $num . " rows returned";
                //closing DB
                $db = NULL;
            }
            else{
                $return["err"] = "DB:Patrons Bind Failed";
                $return["msg"] = $stmt->errorInfo();
            }
        }
        catch(PDOException $e)
        {
            $return["err"] = "DB:Patrons Unhandled PDO Exception";
            $return["msg"] = $e->getMessage();
        }
        
        return $return;
    }

    //Function to search Patron by name, email or phone number
    
    function findPatron($search){
        $return["err"] = '';
        $return["msg"] = '';
        $arr = array();
        
        try {
            /*** connect to SQLite database ***/
            $db = new PDO("sqlite:" . getDBPath("patron"));
            
            $stmt = $db->prepare('SELECT * FROM tb_patrons WHERE name1 LIKE :likesearch OR name2 LIKE :likesearch OR email1 = :fullsearch OR email2 = :fullsearch OR phone1 = :fullsearch OR phone2 = :fullsearch ORDER BY name1');
           
            $like = '%'.$search.'%';
            $full = $search;
            
            $bindVar = $stmt->bindParam(':likesearch', $like);
            $bindVar = $stmt->bindParam(':fullsearch', $full);
            
            
            if($bindVar)
            {
                $num = 0;
                $stmt->execute();
                $result = $stmt->fetchAll();
                foreach($result as $row)
                {
                    $arr[$num] = $row;
                    $num++;
                }
                
                $return["data"] = $arr;
                $return ["msg"] = $num . " rows returned";
                //closing DB
                $db = NULL;
            }
            else{
                $return["err"] = "DB:Patrons Bind Failed";
                $return["msg"] = $stmt->errorInfo();
            }
        }
        catch(PDOException $e)
        {
            $return["err"] = "DB:Patrons Unhandled PDO Exception";
            $return["msg"] = $e->getMessage();
        }
        
        return $return;
    }


    //Function to add patrons

    function addPatron($name1, $name2, $email1, $email2, $phone1, $phone2, $address1, $address2, $city, $state, $zip, $ipaddress){
        $return["err"] = '';
        $return["msg"] = '';
        logMessage(">>>>Adding New Patron record");
        try {
            /*** connect to SQLite database ***/
            $db = new PDO("sqlite:" . getDBPath("patron"));
           
            $stmtIns = $db->prepare("INSERT INTO tb_patrons(date, name1, name2, email1, email2, phone1, phone2, address1, address2, city, state, zip, ipaddress)
                VALUES(:date, :name1, :name2, :email1, :email2, :phone1, :phone2, :add1, :add2, :city, :state, :zip, :ipadd)");

            $bindVar = $stmtIns->bindParam(':date', $date);
            $bindVar = $stmtIns->bindParam(':name1', $name1);
            $bindVar = $stmtIns->bindParam(':name2', $name2);
            $bindVar = $stmtIns->bindParam(':email1', $email1);
            $bindVar = $stmtIns->bindParam(':email2', $email2);
            $bindVar = $stmtIns->bindParam(':phone1', $phone1);
            $bindVar = $stmtIns->bindParam(':phone2', $phone2);
            $bindVar = $stmtIns->bindParam(':add1', $address1);
            $bindVar = $stmtIns->bindParam(':add2', $address2);
            $bindVar = $stmtIns->bindParam(':city', $city);
            $bindVar = $stmtIns->bindParam(':state', $state);
            $bindVar = $stmtIns->bindParam(':zip', $zip);
            $bindVar = $stmtIns->bindParam(':ipadd', $ipaddress);

            
            $date = date("Ymd:His");
            

            if($bindVar)
            {
                // inserting row
                $exec = $stmtIns->execute();

                if($exec){
                    //retrieving last inserted row for ID.
                    $result = $db->query('SELECT last_insert_rowid() AS rowid FROM tb_patrons LIMIT 1');
                    
                    $r = $result->fetch();
                    
                    $lastrow = $r['rowid'];
                    
                    logMessage(">>>>New Patron record:" . $lastrow);
                    $return["msg"] = "PATRON ROW INSERT SUCCESS";
                    $return["data"] = $lastrow;
                }
                else{
                    $return["err"] = "DB:Patrons Insert Failed";
                    $return["msg"] = $stmtIns->errorInfo();
                }
            }
            else{
                $return["err"] = "DB:Patrons Bind Failed";
                $return["msg"] = $stmtIns->errorInfo();
            }

            //closing DB
            $db = NULL;
        }
        catch(PDOException $e)
        {
            logMessage("**DBError:" . $e->getMessage());
            $return["err"] = "DB:Patrons: Unhandled PDO Exception";
            $return["msg"] = $e->getMessage();
        }
        catch(Exception $ex)
        {
            logMessage("**Error:" . $ex->getMessage());
            $return["err"] = "Error:Patrons: Unhandled Exception";
            $return["msg"] = $e->getMessage();
        }
        logMessage(">>>>Patron Insert Complete");
        return $return;
     }
     
     
     //Function to update patrons

    function updatePatron($id, $name1, $name2, $email1, $email2, $phone1, $phone2, $address1, $address2, $city, $state, $zip, $ipaddress){
        $return["err"] = '';
        $return["msg"] = '';
        logMessage(">>>>Updating Patron id: ".$id);
        try {
            /*** connect to SQLite database ***/
            $db = new PDO("sqlite:" . getDBPath("patron"));
           
            $stmt = $db->prepare("UPDATE tb_patrons SET date = :date, name1 = :name1, name2 = :name2, email1 = :email1, email2 = :email2, phone1 = :phone1, phone2 = :phone2, address1 = :add1, address2 = :add2, city = :city, state = :state, zip = :zip, ipaddress = :ipadd WHERE id = :id");

            $bindVar = $stmt->bindParam(':id', $id);
            $bindVar = $stmt->bindParam(':date', $date);
            $bindVar = $stmt->bindParam(':name1', $name1);
            $bindVar = $stmt->bindParam(':name2', $name2);
            $bindVar = $stmt->bindParam(':email1', $email1);
            $bindVar = $stmt->bindParam(':email2', $email2);
            $bindVar = $stmt->bindParam(':phone1', $phone1);
            $bindVar = $stmt->bindParam(':phone2', $phone2);
            $bindVar = $stmt->bindParam(':add1', $address1);
            $bindVar = $stmt->bindParam(':add2', $address2);
            $bindVar = $stmt->bindParam(':city', $city);
            $bindVar = $stmt->bindParam(':state', $state);
            $bindVar = $stmt->bindParam(':zip', $zip);
            $bindVar = $stmt->bindParam(':ipadd', $ipaddress);

            
            $date = date("Ymd:His");
            
            if($bindVar)
            {
                // updating row
                $exec = $stmt->execute();

                if($exec){
                   
                    $return["msg"] = "PATRON ROW UPDATE SUCCESS";
                    $return["data"] = $id;
                    logMessage(">>>>Updated patron id: ".$id);
                }
                else{
                    logMessage(">>**Error: Update failed: ".implode("|", $stmt->errorInfo()));
                    $return["err"] = "DB:Patrons Update Failed";
                    $return["msg"] = $stmt->errorInfo();
                    logMessage($stmt->debugDumpParams());
                }
            }
            else{
                logMessage(">>**Error: Bind failed: ".implode("|", $stmt->errorInfo()));
                $return["err"] = "DB:Patrons Bind Failed";
                $return["msg"] = $stmt->errorInfo();
                
            }

            //closing DB
            $db = NULL;
        }
        catch(PDOException $e)
        {
            logMessage("**DBError:" . $e->getMessage());
            $return["err"] = "DB:Patrons: Unhandled PDO Exception";
            $return["msg"] = $e->getMessage();
        }
        catch(Exception $ex)
        {
            logMessage("**Error:" . $ex->getMessage());
            $return["err"] = "Error:Patrons: Unhandled Exception";
            $return["msg"] = $e->getMessage();
        }
        logMessage(">>>>Patron Update Complete");
        return $return;
     }
     
?>
