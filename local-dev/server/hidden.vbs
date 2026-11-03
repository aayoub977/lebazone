' Runs a .bat file with no window: hidden.vbs <file.bat> [argument]
Set sh = CreateObject("WScript.Shell")
cmd = """" & WScript.Arguments(0) & """"
If WScript.Arguments.Count > 1 Then cmd = cmd & " " & WScript.Arguments(1)
sh.Run cmd, 0, False
