<?hh // strict
class PushD
{
    private string $oldDir;

    public function __construct(string $newDir)
    {
        $this->oldDir = getcwd();
        echo "(pushd) chdir to $newDir\n";
        if (!chdir($newDir)) {
            throw new Exception('Could not chdir');
        }
    }

    public function __destruct()
    {
        $oldDir = $this->oldDir;
        echo "(popd) chdir to $oldDir\n";
        if (!chdir($oldDir)) {
            throw new Exception('Could not chdir to restore');
        }
    }
}
