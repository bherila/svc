// Placeholder: the owner/admin package list is built in slice 1b against these props.
type Props = { packages: { id: string; link_name: string; edit_url: string }[] };

export default function PackagesIndex({ packages }: Props) {
    return (
        <ul>
            {packages.map((pkg) => (
                <li key={pkg.id}>{pkg.link_name}</li>
            ))}
        </ul>
    );
}
