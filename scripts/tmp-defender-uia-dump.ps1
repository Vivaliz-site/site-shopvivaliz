$ErrorActionPreference='Stop'
Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes
$procs=Get-Process opera -ErrorAction SilentlyContinue | Where-Object {$_.MainWindowHandle -ne 0}
foreach($p in $procs){
  Write-Output ('WINDOW|PID='+$p.Id+'|TITLE='+$p.MainWindowTitle+'|HANDLE='+$p.MainWindowHandle+'|SESSION='+$p.SessionId)
  try {
    $root=[System.Windows.Automation.AutomationElement]::FromHandle($p.MainWindowHandle)
    if(-not $root){continue}
    $all=$root.FindAll([System.Windows.Automation.TreeScope]::Descendants,[System.Windows.Automation.Condition]::TrueCondition)
    for($i=0;$i -lt $all.Count;$i++){
      $e=$all.Item($i)
      $n=$e.Current.Name
      if($n -and $n -match 'Sim|N.o|NAORESPONDA|Tem certeza|Desbloquear|Entidades restritas|Aviso|Enviar|Pr.xima'){
        $r=$e.Current.BoundingRectangle
        Write-Output ('ELEM|NAME='+$n+'|TYPE='+$e.Current.ControlType.ProgrammaticName+'|AID='+$e.Current.AutomationId+'|CLASS='+$e.Current.ClassName+'|FRAME='+$e.Current.FrameworkId+'|OFF='+$e.Current.IsOffscreen+'|EN='+$e.Current.IsEnabled+'|X='+[int]$r.X+'|Y='+[int]$r.Y+'|W='+[int]$r.Width+'|H='+[int]$r.Height)
      }
    }
  } catch { Write-Output ('UIA_ERROR|'+$_.Exception.Message) }
}
