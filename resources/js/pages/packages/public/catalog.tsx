// Placeholder: the public catalog page is built in slice 1b against these props.
type Props = { workspace: { name: string }; packages: { name: string; url: string | null }[] };

export default function PublicPackageCatalog({ workspace, packages }: Props) {
    return (
        <main>
            <h1>{workspace.name}</h1>
            <ul>
                {packages.map((pkg) => (
                    <li key={pkg.url ?? pkg.name}>{pkg.name}</li>
                ))}
            </ul>
        </main>
    );
}
