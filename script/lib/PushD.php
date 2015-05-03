<?hh // strict
class PushD
{
    private string $oldDir;

    public function __construct(string $newDir)
    {
        $this->oldDir = getcwd();
        if (!chdir($newDir)) {
            throw new Exception('Could not chdir');
        }
    }

    public function __destruct()
    {
        if (!chdir($this->oldDir)) {
            throw new Exception('Could not chdir to restore');
        }
    }
}
