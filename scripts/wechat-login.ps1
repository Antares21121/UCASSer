param(
    [string]$ArticleUrl = 'https://mp.weixin.qq.com/s/qm122Vd4H62hE4iROnwP9A',
    [string]$ChromePath
)

$uri = $null
if (-not [Uri]::TryCreate($ArticleUrl, [UriKind]::Absolute, [ref]$uri) -or
    $uri.Scheme -ne 'https' -or $uri.Host -ne 'mp.weixin.qq.com') {
    throw '请提供 https://mp.weixin.qq.com/ 下的文章链接。'
}

$chromeCandidates = @(
    $ChromePath,
    "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe",
    "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe"
)
foreach ($key in @(
    'HKCU:\SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\chrome.exe',
    'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\chrome.exe'
)) {
    try { $chromeCandidates += (Get-Item -LiteralPath $key -ErrorAction Stop).GetValue('') } catch { }
}
$chrome = $chromeCandidates | Where-Object { $_ -and (Test-Path -LiteralPath $_) } | Select-Object -First 1
if (-not $chrome) { throw '未找到 Chrome。可通过 -ChromePath 指定 chrome.exe 的完整路径。' }

$profile = Join-Path (Split-Path -Parent $PSScriptRoot) '.runtime\wechat-chrome-profile'
New-Item -ItemType Directory -Force -Path $profile | Out-Null

# Chrome keeps the session in this ignored local folder. The script never reads
# credentials or exports cookies. Re-run it when WeChat requests verification.
Start-Process -FilePath $chrome -ArgumentList @("--user-data-dir=`"$profile`"", '--new-window', $uri.AbsoluteUri)
Write-Host '微信文章已在独立 Chrome 窗口打开。请在窗口中手动完成验证或登录。'
Write-Host '以后验证失效时，再运行同一脚本；本地浏览器状态保存在 .runtime/wechat-chrome-profile。'
