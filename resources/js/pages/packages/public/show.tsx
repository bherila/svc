// Placeholder: the public package page is built in slice 1b against these props.
type Props = { workspace: { name: string }; package: { name: string } };

export default function PublicPackageShow({ workspace, package: pkg }: Props) {
    return (
        <main>
            <p>{workspace.name}</p>
            <h1>{pkg.name}</h1>
        </main>
    );
}
