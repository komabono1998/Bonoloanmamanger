# Running Bono Loan Manager on your office network (LAN)

One computer in the office is the **server**. Everyone else (computers, tablets, phones on the office Wi-Fi) opens the system in a browser. Nothing is sent over the internet.

Good for: one office or one building. Not for branches in different places (they cannot reach the office network); use the Hostinger guide or a VPN for those.

## Quick way
After XAMPP is installed (step 2) and the package is unzipped into `C:\xampp\htdocs\bono`, right-click **`deploy\lan\setup-server.bat` > Run as administrator**. It asks for the drive letter for the data, creates the data folders, writes `env.php` with a fresh secret key, opens the private-network firewall and prints the address to share. Then continue at step 5. (Steps 3 and 4 below describe the same thing done by hand.)

## 1. Choose and prepare the server computer
* A reliable Windows PC (8 GB memory is plenty), **always on during working hours**, ideally on a UPS (battery backup) and cabled to the router.
* Set Windows Update "active hours" so it never restarts in the middle of the working day.
* Make sure the date, time and time zone (East Africa Time) are correct: business dates depend on it.
* Give it a **fixed address**: in the router, reserve an IP for this computer (DHCP reservation) or set a static IP in Windows. Write it down, for example `192.168.1.136`.

## 2. Install the web server (XAMPP)
1. Install XAMPP (PHP 8.2 or newer) to `C:\xampp`.
2. In the XAMPP Control Panel tick **Service** next to *Apache* and start it, so it starts by itself when Windows starts. (You do **not** need MySQL.)

## 3. Put the program and the data in place
1. Unzip the package into `C:\xampp\htdocs\bono` (so `index.php` is at `C:\xampp\htdocs\bono\index.php`).
2. Create the data folders **outside** the website folder, preferably on a second drive: `D:\BonoData\db`, `D:\BonoData\uploads`, `D:\BonoData\backups` (use `C:` if there is no second drive).
3. Create `C:\xampp\htdocs\bono\env.php` from `deploy\lan\env.example.php.txt` (change the secret key and the folder letters).
4. Copy `hostcheck.php` is already in the package root: open `http://localhost/bono/hostcheck.php`, check every line is green, then **delete `hostcheck.php`**.

## 4. Let the other computers reach it
1. Run `deploy\lan\open-firewall.bat` **as Administrator** (right-click > Run as administrator). It lets the office network, and only the private network, reach port 80. Make sure the Wi-Fi/Ethernet is set to **Private** in Windows (Settings > Network).
2. From another computer or phone on the same network open `http://SERVER-ADDRESS/bono/`, for example `http://192.168.1.136/bono/`. Create a bookmark, or put a shortcut on each desk.
3. Optional friendly name: in the router's DNS settings add `bono.office` pointing to the server address, then use `http://bono.office/bono/`.

## 5. First start
1. Open the address. A fresh installation shows a plain sign-in page with one button, **Set up a new organisation**. Create the organisation, its first branch and the System Administrator (strong password), and set up the authenticator app.
2. Then follow `docs/UAT_GUIDE.md` section 1: profile, users, policy, opening balances, start the business day.
3. Create one login per person. Staff use their own phone or computer; on phones the system works like an app ("Add to home screen").

## 6. Backups (essential on a LAN: there is no hosting company behind you)
* The system makes its own consistent backups before End of Day (Backup & Recovery lists them) into `D:\BonoData\backups`.
* **Copy them elsewhere every day**: use `deploy\lan\backup-copy.bat` with Windows *Task Scheduler* (steps are inside the file). Point it at a USB disk, a network drive or a cloud-synced folder, **not** the same disk.
* Once a month, restore a backup on a spare computer to prove it works.

## 7. Keep it safe
* Keep the server in a locked room; only the administrator signs in to Windows on it.
* Do not forward port 80 on the router to the internet. (If you later want outside access, use the Hostinger route or a VPN.)
* If guests use the same Wi-Fi, switch on "client isolation"/a separate guest network so they cannot reach the server.
* Set a password for MySQL's `root` in XAMPP (phpMyAdmin is only reachable from the server itself, but still).
* Staff must not share logins: everything is recorded under the name of the signed-in person.
* Windows and antivirus updates: install them on a weekend.

## 8. Day-to-day
* Start of day: the Branch Manager checks the system date; tills are opened by cashiers.
* End of day: tills closed and counted, then the System Administrator runs **End of Day** (End of Day page) and the date moves on.
* Updating the program later: take a backup, then copy the new files over `C:\xampp\htdocs\bono` (never over `env.php` or `D:\BonoData`). Database changes apply automatically.

## Troubleshooting
| Symptom | What to do |
|---------|------------|
| Other computers cannot open the address | Run `open-firewall.bat` as Administrator; check the network is "Private"; check the server's address with `ipconfig`; try from the server itself first (`http://localhost/bono/`) |
| Works on the server only | Firewall (above), or you typed `localhost` on the other computer |
| Address stopped working after a restart | The router gave the server a new address: reserve one (step 1) |
| Blank page / error | Open `C:\xampp\htdocs\bono\storage\logs\php-error.log`; check `env.php` for typing errors and that the data folders exist |
| "Database is locked" | Wait a few seconds and retry; make sure only one copy of the program uses the data folder |
